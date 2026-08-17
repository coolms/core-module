<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Backup;

use CoolMS\Core\Backup\BackupContributorInterface;
use CoolMS\Core\Backup\BackupException;
use CoolMS\Core\Backup\BackupReaderInterface;
use CoolMS\Core\Backup\BackupTier;
use CoolMS\Core\Backup\BackupWriterInterface;
use CoolMS\Core\Backup\ReconcilesDeletesInterface;
use CoolMS\Core\Backup\TableBackupPortInterface;
use CoolMS\CoreModule\Backup\BackupRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function file_get_contents;
use function is_file;
use function json_decode;
use function random_bytes;
use function sys_get_temp_dir;
use function ucfirst;

use const JSON_THROW_ON_ERROR;

/**
 * @covers \CoolMS\CoreModule\Backup\BackupRunner
 */
final class BackupRunnerTest extends TestCase
{
    private string $dir;
    private Filesystem $fs;

    #[Test]
    public function createWritesManifestAndPayloadsFilteredByTier(): void
    {
        $log = new CallLog();
        $runner = $this->runner([
            new FakeBackupContributor('calendar', BackupTier::Config, [], 3, $log),
            new FakeBackupContributor('analytics', BackupTier::Runtime, [], 9, $log),
        ]);

        $report = $runner->create($this->dir, [BackupTier::Config], []);

        // Only the Config-tier contributor is included.
        self::assertCount(1, $report);
        self::assertSame('calendar', $report[0]['key']);
        self::assertSame(3, $report[0]['records']);

        self::assertTrue(is_file($this->dir . '/manifest.json'));
        self::assertTrue(is_file($this->dir . '/README.txt'));
        self::assertTrue(is_file($this->dir . '/data/calendar/marker.json'), 'contributor payload is written under data/<key>/');
        self::assertFalse(is_file($this->dir . '/data/analytics/marker.json'), 'Runtime-tier contributor excluded by the Config profile');

        $manifest = $this->readManifestArray();
        self::assertSame(1, $manifest['formatVersion']);
        self::assertSame('2026-07-15T12:00:00+00:00', $manifest['createdAt']);
        self::assertSame(['config'], $manifest['tiers']);
        self::assertContains('COOLMS_SECRET_MASTER_KEY', $manifest['excluded'], 'manifest self-documents the secret exclusion');
    }

    #[Test]
    public function restoreImportsInDependencyOrder(): void
    {
        $log = new CallLog();
        // 'child' must import AFTER 'parent'; register them OUT of order to prove sorting.
        $runner = $this->runner([
            new FakeBackupContributor('child', BackupTier::Config, ['parent'], 1, $log),
            new FakeBackupContributor('parent', BackupTier::Config, [], 1, $log),
        ]);

        $runner->create($this->dir, [BackupTier::Config], []);
        $runner->restore($this->dir, false, []);

        self::assertSame(['parent', 'child'], $log->imported);
    }

    #[Test]
    public function dryRunRestoreImportsNothing(): void
    {
        $log = new CallLog();
        $runner = $this->runner([new FakeBackupContributor('calendar', BackupTier::Config, [], 2, $log)]);

        $runner->create($this->dir, [BackupTier::Config], []);
        $report = $runner->restore($this->dir, true, []);

        self::assertSame([], $log->imported, 'dry-run must not invoke any import');
        self::assertSame('would restore', $report[0]['status']);
    }

    #[Test]
    public function restoreRefusesANewerBundleFormat(): void
    {
        $this->fs->dumpFile(
            $this->dir . '/manifest.json',
            '{"formatVersion":999,"createdAt":"2026-01-01T00:00:00+00:00","tiers":["config"],"contributors":[]}',
        );
        $runner = $this->runner([]);

        $this->expectException(BackupException::class);
        $runner->restore($this->dir, false, []);
    }

    #[Test]
    public function restoreSkipsContributorsAbsentOnThisInstance(): void
    {
        $log = new CallLog();
        $writeRunner = $this->runner([new FakeBackupContributor('ghost', BackupTier::Config, [], 1, $log)]);
        $writeRunner->create($this->dir, [BackupTier::Config], []);

        // Restore with NO 'ghost' registered — it should be reported as skipped, not fatal.
        $report = $this->runner([])->restore($this->dir, false, []);

        self::assertCount(1, $report);
        self::assertSame('ghost', $report[0]['key']);
        self::assertStringContainsString('skipped', $report[0]['status']);
    }

    #[Test]
    public function restoreDoesNotReconcileByDefault(): void
    {
        $log = new CallLog();
        $runner = $this->runner([new ReconcilingFakeContributor('sso', [], $log, 3)]);

        $runner->create($this->dir, [BackupTier::Config], []);
        $report = $runner->restore($this->dir, false, []); // reconcileDeletes defaults to false

        self::assertSame([], $log->reconciled, 'the default restore stays purely additive');
        self::assertSame(0, $report[0]['deleted']);
        self::assertSame('restored', $report[0]['status']);
    }

    #[Test]
    public function reconcilePassRunsInReverseDependencyOrderForCapableContributorsOnly(): void
    {
        $log = new CallLog();
        // import order: parent -> child -> grandchild; 'parent' is NOT reconcile-capable.
        $runner = $this->runner([
            new ReconcilingFakeContributor('grandchild', ['child'], $log, 5),
            new FakeBackupContributor('parent', BackupTier::Config, [], 1, $log),
            new ReconcilingFakeContributor('child', ['parent'], $log, 2),
        ]);

        $runner->create($this->dir, [BackupTier::Config], []);
        $report = $runner->restore($this->dir, false, [], true);

        self::assertSame(['parent', 'child', 'grandchild'], $log->imported);
        // Reconcile = REVERSE of import order, skipping the non-capable 'parent'.
        self::assertSame(['grandchild', 'child'], $log->reconciled);

        $byKey = $this->indexByKey($report);
        self::assertSame(5, $byKey['grandchild']['deleted']);
        self::assertSame(2, $byKey['child']['deleted']);
        self::assertSame(0, $byKey['parent']['deleted'], 'a contributor without the capability is never reconciled');
        self::assertStringContainsString('(deleted 2)', $byKey['child']['status']);
    }

    #[Test]
    public function dryRunReconcileReportsWhatWouldBeDeletedWithoutDeleting(): void
    {
        $log = new CallLog();
        $runner = $this->runner([new ReconcilingFakeContributor('sso', [], $log, 4)]);

        $runner->create($this->dir, [BackupTier::Config], []);
        $report = $runner->restore($this->dir, true, [], true);

        self::assertSame(['sso'], $log->reconciled);
        self::assertTrue($log->reconcileDryRun, 'the dry-run flag propagates to the contributor');
        self::assertSame(4, $report[0]['deleted']);
        self::assertStringContainsString('would delete 4', $report[0]['status']);
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/coolms-backup-runner-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    /**
     * @param list<BackupContributorInterface> $contributors
     */
    private function runner(array $contributors): BackupRunner
    {
        return new BackupRunner(
            $contributors,
            $this->createStub(TableBackupPortInterface::class),
            new MockClock('2026-07-15T12:00:00+00:00'),
        );
    }

    /**
     * @param list<array{key: string, label: string, restored: int, status: string, deleted: int}> $report
     *
     * @return array<string, array{key: string, label: string, restored: int, status: string, deleted: int}>
     */
    private function indexByKey(array $report): array
    {
        $byKey = [];
        foreach ($report as $row) {
            $byKey[$row['key']] = $row;
        }

        return $byKey;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifestArray(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($this->dir . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}

/**
 * Mutable call recorder shared across the fakes in one test (constructor
 * promotion can't take a by-ref array, so we share a small object).
 */
final class CallLog
{
    /** @var list<string> */
    public array $imported = [];

    /** @var list<string> */
    public array $reconciled = [];

    public ?bool $reconcileDryRun = null;
}

/**
 * Test double: exports a marker payload (no DB) and records its import order.
 */
final class FakeBackupContributor implements BackupContributorInterface
{
    /**
     * @param list<string> $after
     */
    public function __construct(
        private readonly string $key,
        private readonly BackupTier $tier,
        private readonly array $after,
        private readonly int $count,
        private readonly CallLog $log,
    ) {
    }

    public function backupKey(): string
    {
        return $this->key;
    }

    public function backupLabel(): string
    {
        return ucfirst($this->key);
    }

    public function tier(): BackupTier
    {
        return $this->tier;
    }

    public function restoreAfter(): array
    {
        return $this->after;
    }

    public function tables(): array
    {
        return ['coolms_fake_' . $this->key];
    }

    public function export(BackupWriterInterface $writer): int
    {
        $writer->putJson('marker', ['key' => $this->key]);

        return $this->count;
    }

    public function import(BackupReaderInterface $reader): int
    {
        $this->log->imported[] = $this->key;

        return $this->count;
    }
}

/**
 * Test double that ALSO reconciles deletes — records its reconcile order + dry-run
 * flag and returns a fixed deleted count (no DB).
 */
final class ReconcilingFakeContributor implements BackupContributorInterface, ReconcilesDeletesInterface
{
    /**
     * @param list<string> $after
     */
    public function __construct(
        private readonly string $key,
        private readonly array $after,
        private readonly CallLog $log,
        private readonly int $deleteCount,
    ) {
    }

    public function backupKey(): string
    {
        return $this->key;
    }

    public function backupLabel(): string
    {
        return ucfirst($this->key);
    }

    public function tier(): BackupTier
    {
        return BackupTier::Config;
    }

    public function restoreAfter(): array
    {
        return $this->after;
    }

    public function tables(): array
    {
        return ['coolms_fake_' . $this->key];
    }

    public function export(BackupWriterInterface $writer): int
    {
        $writer->putJson('marker', ['key' => $this->key]);

        return 1;
    }

    public function import(BackupReaderInterface $reader): int
    {
        $this->log->imported[] = $this->key;

        return 1;
    }

    public function reconcileDeletes(BackupReaderInterface $reader, bool $dryRun): int
    {
        $this->log->reconciled[] = $this->key;
        $this->log->reconcileDryRun = $dryRun;

        return $this->deleteCount;
    }
}
