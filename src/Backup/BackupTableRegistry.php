<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupContributorInterface;
use CoolMS\Core\Backup\BackupTier;
use CoolMS\Core\Backup\DefersRestoreColumnsInterface;
use CoolMS\Core\Backup\SyncedTableSetInterface;
use CoolMS\Core\Backup\SyncsAsOwnedCollectionInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_keys;
use function array_map;
use function array_search;
use function ksort;
use function usort;

use const PHP_INT_MAX;

/**
 * The authoritative set of DB tables the backup contributors serialise — i.e. the
 * "synced universe". Aggregates every {@see BackupContributorInterface::tables()}
 * over the same tagged iterator {@see BackupRunner} runs, so the enumeration stays
 * provably in step with what backup actually exports as contributors evolve.
 *
 * Why this exists (controller→edge sync): the event-driven change-feed's
 * row-change CAPTURE scope must equal what backup EXPORTS — a table synced by backup
 * but NOT captured by the feed would silently drift on edges (the failure mode of
 * sourcing the delta from a partial log, §3). Before this registry there was no
 * central "is table X synced?" answer — each contributor privately knew its own
 * tables. The future flush-listener capture filters on {@see covers()}; a table with
 * no backup contributor (e.g. Runtime-tier `coolms_workflow_process_instances`, or
 * the feed's own `coolms_sync_changes`) is NOT synced and is skipped.
 *
 * The `#[AutowireIterator]` pin is deliberate — the same tagged-iterator glob footgun
 * {@see BackupRunner} documents (a Core-Extension `setArgument` would be clobbered by
 * the `App\:` services glob re-registering this class).
 */
#[Autoconfigure(public: true)] // no runtime consumer yet (the change-feed listener, B.2.2); survive pruning
// The alias is what lets the persistence adapters ask `covers()`/`ownerColumnFor()`
// through the Core contract instead of importing this class -- see
// {@see SyncedTableSetInterface}. Without it their interface type-hint has
// nothing to resolve to.
#[AsAlias(SyncedTableSetInterface::class)]
final class BackupTableRegistry implements SyncedTableSetInterface
{
    /** @var array<string, true>|null memoised synced-table set (this is a singleton service) */
    private ?array $set = null;

    /** @var list<string>|null memoised FK-safe table order */
    private ?array $ordered = null;

    /** @var array<string, list<string>>|null memoised deferred-column map */
    private ?array $deferred = null;

    /** @var array<string, string>|null memoised owned-collection map (table → owner column) */
    private ?array $ownerColumns = null;

    /** @var array<string, BackupTier>|null memoised table → tier map */
    private ?array $tiers = null;

    /**
     * @param iterable<BackupContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator('coolms.backup.contributor')]
        private readonly iterable $contributors,
    ) {
    }

    /**
     * Every synced table, de-duplicated and sorted (a table exported by more than
     * one contributor appears once).
     *
     * @return list<string>
     */
    public function allTables(): array
    {
        return array_keys($this->syncedSet());
    }

    /** Whether `$table` is part of the synced universe (O(1); the change-feed's capture filter). */
    public function covers(string $table): bool
    {
        return isset($this->syncedSet()[$table]);
    }

    /**
     * Every synced table in FK-SAFE RESTORE ORDER: referenced tables before the tables
     * that reference them, across modules AND within each module. Write in this order;
     * DELETE in its reverse.
     *
     * The order is composed from what the contributors already declare — the same two
     * facts a bundle restore walks — rather than introspected from the DB:
     *  1. `restoreAfter()` topo-sorted → cross-MODULE order (Identity before Calendar);
     *  2. each contributor's own `tables()` order → intra-module order (calendars before
     *     items), which is contractual, see {@see BackupContributorInterface::tables()}.
     *
     * **Why it can't just be {@see allTables()}:** that one is `ksort`ed into a SET for
     * `covers()`, which is the right shape for "is X synced?" and useless for "what may
     * I write first?". No FK order was exposed anywhere before this, so
     * {@see \CoolMS\CoreModule\ChangeFeed\SyncChangeApplier} — which replays these
     * tables WITHOUT going through any contributor — had nothing to order by.
     *
     * Ties (a table exported by two contributors) keep the FIRST occurrence, i.e. the
     * earlier contributor's position, which is the safe one.
     *
     * @return list<string>
     */
    public function orderedTables(): array
    {
        if (null !== $this->ordered) {
            return $this->ordered;
        }

        $registered = [];
        foreach ($this->contributors as $contributor) {
            $registered[$contributor->backupKey()] = $contributor;
        }

        $keys = array_keys($registered);
        $ordered = [];
        $seen = [];

        foreach (new ContributorRestoreOrder()->order($keys, $registered) as $key) {
            foreach ($registered[$key]->tables() as $table) {
                if (!isset($seen[$table])) {
                    $seen[$table] = true;
                    $ordered[] = $table;
                }
            }
        }

        return $this->ordered = $ordered;
    }

    /**
     * The columns to hold back to a second pass when restoring `$table` — the
     * self-referential FK hazard no table order can fix. Empty for most tables.
     * Declared by {@see DefersRestoreColumnsInterface}; see
     * {@see BackupReader::loadTableDeferring()} for the mechanics and the
     * CHECK-coherence rule.
     *
     * @return list<string>
     */
    public function deferredColumnsFor(string $table): array
    {
        if (null === $this->deferred) {
            $map = [];
            foreach ($this->contributors as $contributor) {
                if ($contributor instanceof DefersRestoreColumnsInterface) {
                    foreach ($contributor->deferredRestoreColumns() as $declaredTable => $columns) {
                        $map[$declaredTable] = $columns;
                    }
                }
            }
            $this->deferred = $map;
        }

        return $this->deferred[$table] ?? [];
    }

    /**
     * The column naming the OWNER of `$table`'s rows, or null if `$table` is an ordinary
     * table of independently-keyed rows (the overwhelming majority — the declaration is
     * opt-in via {@see SyncsAsOwnedCollectionInterface}).
     *
     * **This is THE key-column question for the change feed**, and every side must ask it
     * here rather than assuming `id`: capture keys an owned-collection delta by the owner
     * (the persistence adapter's change-capture listener), hydration fetches
     * by the same column (the adapter's local row source and the
     * controller's `SyncRowsController`), and the applier purges by it before re-inserting
     * ({@see \CoolMS\CoreModule\ChangeFeed\SyncChangeApplier}). One declaration, four
     * readers, so the ends provably cannot disagree about what a `row_id` means.
     */
    public function ownerColumnFor(string $table): ?string
    {
        if (null === $this->ownerColumns) {
            $map = [];
            foreach ($this->contributors as $contributor) {
                if ($contributor instanceof SyncsAsOwnedCollectionInterface) {
                    foreach ($contributor->ownedCollectionTables() as $declaredTable => $ownerColumn) {
                        $map[$declaredTable] = $ownerColumn;
                    }
                }
            }
            $this->ownerColumns = $map;
        }

        return $this->ownerColumns[$table] ?? null;
    }

    /**
     * Sort `$tables` into {@see orderedTables()} order. Tables outside the synced
     * universe keep their relative position at the END — the caller is writing
     * something the registry knows nothing about, so we must not claim to have
     * ordered it safely.
     *
     * @param list<string> $tables
     *
     * @return list<string>
     */
    public function sortByRestoreOrder(array $tables): array
    {
        $order = $this->orderedTables();

        $ranked = [];
        foreach ($tables as $i => $table) {
            $rank = array_search($table, $order, true);
            $ranked[] = [false === $rank ? PHP_INT_MAX : $rank, $i, $table];
        }

        usort($ranked, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $r): string => $r[2], $ranked);
    }

    /**
     * The {@see BackupTier} of the contributor that exports `$table`, or null for a
     * table outside the synced universe.
     *
     * This map is a later addition — `syncedSet()` deliberately flattens
     * contributors away for `covers()` — and its absence is what made per-edge
     * scope unenforceable at the change-feed/rows/blobs endpoints:
     * scope is expressed in TIERS (the axis `BackupRunner::select()` already
     * filters snapshots by), while those endpoints speak TABLES. This is the
     * bridge. A table exported by two contributors keeps the FIRST contributor's
     * tier, matching {@see orderedTables()}'s tie rule.
     */
    public function tierFor(string $table): ?BackupTier
    {
        if (null === $this->tiers) {
            $map = [];
            foreach ($this->contributors as $contributor) {
                foreach ($contributor->tables() as $declaredTable) {
                    $map[$declaredTable] ??= $contributor->tier();
                }
            }
            $this->tiers = $map;
        }

        return $this->tiers[$table] ?? null;
    }

    /**
     * @return array<string, true>
     */
    private function syncedSet(): array
    {
        if (null !== $this->set) {
            return $this->set;
        }

        $set = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->tables() as $table) {
                $set[$table] = true;
            }
        }
        ksort($set); // stable, sorted order for allTables()

        return $this->set = $set;
    }
}
