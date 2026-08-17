<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupContributorInterface;
use CoolMS\Core\Backup\BackupException;
use CoolMS\Core\Backup\BackupTier;
use CoolMS\Core\Backup\ReconcilesDeletesInterface;
use CoolMS\Core\Backup\TableBackupPortInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Filesystem\Filesystem;

use function array_map;
use function array_reverse;
use function array_values;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function usort;

use const DATE_ATOM;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Runs every registered {@see BackupContributorInterface} to build or replay a
 * backup bundle — the single seam behind `coolms:backup:create` and
 * `coolms:backup:restore`, so the whole platform is backed up/restored in one
 * place with each module owning its slice.
 *
 * The `#[AutowireIterator]` pin is deliberate (the tagged-iterator glob footgun,
 * per the retention runner): binding through the Core Extension's `setArgument`
 * would be clobbered by the `App\:` services glob re-registering this class.
 *
 * SECURITY: this runner only ever invokes contributors, which serialise their
 * own DB rows/blobs. It never reads secrets, `.env*`, or `var/secrets/`; the
 * manifest records that exclusion so the bundle self-documents its safety.
 */
final readonly class BackupRunner
{
    private Filesystem $filesystem;

    /**
     * @param iterable<BackupContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator('coolms.backup.contributor')]
        private iterable $contributors,
        private TableBackupPortInterface $tables,
        private ClockInterface $clock,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Export the selected tiers into a fresh bundle at `$bundleDir`. Writes
     * `manifest.json` + `README.txt` + `data/<key>/<table>.json` payloads.
     *
     * @param list<BackupTier> $tiers    which data classes to include
     * @param list<string>     $onlyKeys restrict to these backup keys (empty = all)
     *
     * @return list<array{key: string, label: string, tier: string, records: int}>
     */
    public function create(string $bundleDir, array $tiers, array $onlyKeys = []): array
    {
        $selected = $this->select($tiers, $onlyKeys);

        $report = [];
        $manifestContributors = [];
        foreach ($selected as $contributor) {
            $writer = new BackupWriter($bundleDir, $contributor->backupKey(), $this->tables);
            $records = $contributor->export($writer);

            $report[] = [
                'key' => $contributor->backupKey(),
                'label' => $contributor->backupLabel(),
                'tier' => $contributor->tier()->value,
                'records' => $records,
            ];
            $manifestContributors[] = [
                'key' => $contributor->backupKey(),
                'label' => $contributor->backupLabel(),
                'tier' => $contributor->tier()->value,
                'tables' => $writer->tablesWritten(),
                'records' => $records,
            ];
        }

        $manifest = new BackupManifest(
            BackupManifest::FORMAT_VERSION,
            $this->clock->now()->format(DATE_ATOM),
            array_map(static fn (BackupTier $t): string => $t->value, $tiers),
            $manifestContributors,
        );

        $this->filesystem->dumpFile(
            sprintf('%s/manifest.json', $bundleDir),
            json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $this->filesystem->dumpFile(sprintf('%s/README.txt', $bundleDir), $this->readmeText());

        return $report;
    }

    /**
     * Replay a bundle at `$bundleDir` through the registered contributors,
     * ordered by their `restoreAfter` dependencies. `$dryRun` reports the plan
     * without writing. Contributors present in the bundle but not registered on
     * this instance are reported as skipped.
     *
     * When `$reconcileDeletes` is set, a SECOND pass runs after the additive
     * restore — in REVERSE dependency order (children before parents) — invoking
     * every contributor that implements {@see ReconcilesDeletesInterface} to delete
     * its live rows absent from the snapshot. This is opt-in and
     * DESTRUCTIVE; the default restore stays purely additive. Contributors without
     * the capability are simply not reconciled (still additive-only).
     *
     * @param list<string> $onlyKeys restrict to these backup keys (empty = all)
     *
     * @return list<array{key: string, label: string, restored: int, status: string, deleted: int}>
     */
    public function restore(string $bundleDir, bool $dryRun = false, array $onlyKeys = [], bool $reconcileDeletes = false): array
    {
        $manifest = $this->readManifest($bundleDir);
        $manifest->assertRestorable();

        $registered = [];
        foreach ($this->all() as $contributor) {
            $registered[$contributor->backupKey()] = $contributor;
        }

        // Restore only bundle keys that we (a) have a contributor for and (b) aren't filtered out.
        $planKeys = [];
        $report = [];
        foreach ($manifest->contributors as $entry) {
            $key = $entry['key'];
            if ([] !== $onlyKeys && !in_array($key, $onlyKeys, true)) {
                continue;
            }
            if (!isset($registered[$key])) {
                $report[$key] = ['key' => $key, 'label' => $entry['label'], 'restored' => 0, 'status' => 'skipped (no contributor on this instance)', 'deleted' => 0];

                continue;
            }
            $planKeys[] = $key;
        }

        foreach ($this->orderByDependencies($planKeys, $registered) as $key) {
            $contributor = $registered[$key];
            if ($dryRun) {
                $report[$key] = ['key' => $key, 'label' => $contributor->backupLabel(), 'restored' => 0, 'status' => 'would restore', 'deleted' => 0];

                continue;
            }
            $reader = new BackupReader($bundleDir, $key, $this->tables);
            $report[$key] = ['key' => $key, 'label' => $contributor->backupLabel(), 'restored' => $contributor->import($reader), 'status' => 'restored', 'deleted' => 0];
        }

        if ($reconcileDeletes) {
            // Reverse dependency order: delete a dependent's stale rows before the
            // rows it references, so an FK never blocks the delete.
            foreach (array_reverse($this->orderByDependencies($planKeys, $registered)) as $key) {
                $contributor = $registered[$key];
                if (!$contributor instanceof ReconcilesDeletesInterface) {
                    continue;
                }
                $reader = new BackupReader($bundleDir, $key, $this->tables);
                $deleted = $contributor->reconcileDeletes($reader, $dryRun);
                $row = $report[$key];
                $row['deleted'] = $deleted;
                $row['status'] .= $dryRun ? sprintf(' (would delete %d)', $deleted) : sprintf(' (deleted %d)', $deleted);
                $report[$key] = $row;
            }
        }

        return array_values($report);
    }

    public function readManifest(string $bundleDir): BackupManifest
    {
        $path = sprintf('%s/manifest.json', $bundleDir);
        if (!is_file($path)) {
            throw new BackupException(sprintf('No backup manifest at "%s" — not a valid bundle.', $path));
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new BackupException('Backup manifest is not a JSON object.');
        }

        /* @var array<string, mixed> $decoded */
        return BackupManifest::fromArray($decoded);
    }

    /**
     * @param list<BackupTier> $tiers
     * @param list<string>     $onlyKeys
     *
     * @return list<BackupContributorInterface>
     */
    private function select(array $tiers, array $onlyKeys): array
    {
        $selected = [];
        foreach ($this->all() as $contributor) {
            if (!in_array($contributor->tier(), $tiers, true)) {
                continue;
            }
            if ([] !== $onlyKeys && !in_array($contributor->backupKey(), $onlyKeys, true)) {
                continue;
            }
            $selected[] = $contributor;
        }

        // Deterministic export order by key.
        usort($selected, static fn (BackupContributorInterface $a, BackupContributorInterface $b): int => $a->backupKey() <=> $b->backupKey());

        return $selected;
    }

    /**
     * @return list<BackupContributorInterface>
     */
    private function all(): array
    {
        return iterator_to_array($this->contributors, false);
    }

    /**
     * Order `$keys` so every key's `restoreAfter` dependency (that is also in
     * the set) precedes it. Dependencies outside the set are ignored (a bundle
     * may legitimately omit them). Throws on a dependency cycle.
     *
     * @param list<string>                              $keys
     * @param array<string, BackupContributorInterface> $registered
     *
     * @return list<string>
     */
    private function orderByDependencies(array $keys, array $registered): array
    {
        return new ContributorRestoreOrder()->order($keys, $registered);
    }

    private function readmeText(): string
    {
        return <<<TXT
            CoolMS backup bundle
            ====================

            Layout:
              manifest.json         - format version, created-at, tiers, per-module record counts
              data/<key>/<table>.json - one file per module table (row-level, UUID-keyed)

            SECURITY: this bundle contains NO secrets and NO master key. Excluded by
            construction: COOLMS_SECRET_MASTER_KEY, COOLMS_SECRET_*/VAULT_TOKEN, .env*
            files, and var/secrets/. Any ciphertext columns inside (e.g. sealed mailbox
            credentials) are opaque and USELESS without the master key.

            To restore on a remote: provision COOLMS_SECRET_MASTER_KEY out-of-band, run
            coolms:install, then coolms:backup:restore <this-dir>. Restore is idempotent
            (upsert-by-UUID) and safe to re-run.
            TXT;
    }
}
