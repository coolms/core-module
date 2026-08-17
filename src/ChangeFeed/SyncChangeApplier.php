<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ChangeFeed;

use CoolMS\Core\Backup\TableBackupPortInterface;
use CoolMS\Core\ChangeFeed\SyncApplyResult;
use CoolMS\Core\ChangeFeed\SyncChangeDelta;
use CoolMS\Core\ChangeFeed\SyncChangeOp;
use CoolMS\Core\ChangeFeed\SyncRowSourceInterface;
use CoolMS\CoreModule\Backup\BackupTableRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

use function array_keys;
use function array_reverse;
use function array_values;
use function is_scalar;
use function max;

/**
 * Applies a batch of change-feed deltas to the local DB — the CONVERGE step of the
 * controller→edge apply half. Given the deltas an edge read from the feed
 * ({@see \CoolMS\Core\ChangeFeed\SyncChangeFeedReaderInterface}) plus a
 * {@see SyncRowSourceInterface} pointing at the authoritative side, it makes the local
 * rows match: hydrate + `restoreRows` each `upsert`, `deleteByIds` each `delete`.
 *
 * **Coalesce first (latest-`seq` op wins per row):** a row changed N times in the batch is
 * applied ONCE at its final state — upsert-then-delete collapses to a delete, delete-then-
 * upsert to an upsert. This is why the lean feed + current-state hydration is enough: only
 * the row's END state matters, never its intermediate history.
 *
 * **Idempotent, no wrapping transaction** (the {@see TableBackupPortInterface} convention):
 * `restoreRows` is delete-by-id + insert and `deleteByIds` is a plain delete, so re-running
 * a batch is a no-op — a partial apply self-heals on the next pass rather than needing a
 * fragile nested transaction.
 *
 * **FK ORDERING (was a documented gap; the claim that it only failed LOUDLY was
 * wrong).** This engine never calls a contributor: it writes straight through
 * {@see TableBackupPortInterface}, so it does not inherit the FK discipline each
 * contributor's `import()` applies to the very same tables. Two distinct hazards, two
 * answers, both sourced from {@see BackupTableRegistry} so contributors stay the single
 * place FK truth is written down:
 *  - **Cross-table** (`coolms_calendar_items` → `coolms_calendar_calendars`): upserts run
 *    in {@see BackupTableRegistry::orderedTables()} order, deletes in its REVERSE — parents
 *    land before children, children die before parents.
 *  - **Self-referential** (`calendars.parent_id`, `items.parent_item_id`,
 *    `vfs_nodes.parent_id`): the deferral pass, per
 *    {@see BackupTableRegistry::deferredColumnsFor()}. This one was NOT merely a loud
 *    failure — `restoreRows` is delete-by-id + insert per row, so a batch carrying a
 *    parent and its child SILENTLY blanked (SET NULL) or deleted (CASCADE) the child's
 *    link, exactly as was found on the restore path. Nothing threw.
 *
 * Ordering can't fix everything: the Definition ladder's circular FK still needs its
 * two-phase repoint, which no ordering expresses — a batch touching it FK-violates and
 * throws (loud + retryable, never silent).
 *
 * `$highestSeq` is reported for cursor advancement; the commit-ordering safe-watermark is
 * the edge pull loop's concern (B.2.4c), not the applier's.
 */
#[Autoconfigure(public: true)] // no runtime consumer yet (the edge pull command, B.2.4c); survive pruning
final readonly class SyncChangeApplier
{
    public function __construct(
        private TableBackupPortInterface $tables,
        private BackupTableRegistry $registry,
    ) {
    }

    /**
     * @param list<SyncChangeDelta> $deltas
     */
    public function apply(array $deltas, SyncRowSourceInterface $source): SyncApplyResult
    {
        // Coalesce: keep the highest-seq delta per (table, row) — its op is the final state.
        /** @var array<string, SyncChangeDelta> $winner */
        $winner = [];
        $highestSeq = 0;
        foreach ($deltas as $delta) {
            $highestSeq = max($highestSeq, $delta->seq);
            $key = $delta->tableName . "\0" . $delta->rowId;
            if (!isset($winner[$key]) || $delta->seq > $winner[$key]->seq) {
                $winner[$key] = $delta;
            }
        }

        // Partition the winners into per-table upsert / delete id-sets (de-duplicated).
        /** @var array<string, array<string, string>> $upserts */
        $upserts = [];
        /** @var array<string, array<string, string>> $deletes */
        $deletes = [];
        foreach ($winner as $delta) {
            if (SyncChangeOp::Upsert === $delta->op) {
                $upserts[$delta->tableName][$delta->rowId] = $delta->rowId;
            } else {
                $deletes[$delta->tableName][$delta->rowId] = $delta->rowId;
            }
        }

        // Parents before children (a child's FK must resolve at insert time).
        $upserted = 0;
        $deleted = 0;
        foreach ($this->registry->sortByRestoreOrder(array_keys($upserts)) as $table) {
            $ids = array_values($upserts[$table]);
            $rows = $source->fetchRows($table, $ids);

            $ownerColumn = $this->registry->ownerColumnFor($table);
            if (null !== $ownerColumn) {
                // OWNED-COLLECTION SET-REPLACE. Here `$ids` are OWNER ids and the
                // delta means "this owner's set changed", so purge the owner's rows and
                // re-insert whatever the source now holds. Purge FIRST: these rows have no
                // `id`, so `restoreRows` skips its delete-by-id and plain-inserts — without
                // the purge a re-added member would violate the composite PK. An emptied
                // set (cleared, or the owner deleted) hydrates zero rows and simply stays
                // purged, which is exactly how a `clear()` converges without anyone ever
                // knowing WHICH members went.
                $deleted += $this->tables->deleteByIds($table, $ownerColumn, $ids);
                $upserted += $this->tables->restoreRows($table, $rows);
                continue;
            }

            $upserted += $this->applyUpserts($table, $rows);
        }

        // Children before parents (the mirror: a parent's delete must not be blocked by,
        // or silently cascade into, a child this batch still intends to keep).
        foreach (array_reverse($this->registry->sortByRestoreOrder(array_keys($deletes))) as $table) {
            $deleted += $this->tables->deleteByIds($table, $this->keyColumn($table), array_values($deletes[$table]));
        }

        return new SyncApplyResult($upserted, $deleted, $highestSeq);
    }

    /**
     * The column a delta's `row_id` names in `$table`: the owner column for an
     * owned-collection table, `id` for everything else. Never assume `id` — the one place
     * the feed's column name lies is exactly the case that breaks silently.
     */
    private function keyColumn(string $table): string
    {
        return $this->registry->ownerColumnFor($table) ?? 'id';
    }

    /**
     * Insert/replace `$rows`, holding back any self-referential columns the owning
     * contributor declared and re-applying them once every row is in place — the same
     * two-phase dance {@see \CoolMS\CoreModule\Backup\BackupReaderInterface::loadTableDeferring()}
     * runs on the restore path, for the same reason: a batch carrying a parent and its
     * child otherwise loses the link SILENTLY (the parent's delete-by-id fires SET NULL
     * or CASCADE at the child that was just inserted).
     *
     * @param list<array<int|string, mixed>> $rows
     */
    private function applyUpserts(string $table, array $rows): int
    {
        $deferred = $this->registry->deferredColumnsFor($table);
        if ([] === $deferred) {
            return $this->tables->restoreRows($table, $rows);
        }

        /** @var array<string, array<string, mixed>> $repoint */
        $repoint = [];
        $nulled = [];

        foreach ($rows as $row) {
            $pending = [];
            foreach ($deferred as $column) {
                if (null !== ($row[$column] ?? null)) {
                    $pending[$column] = $row[$column];
                    $row[$column] = null;
                }
            }

            $nulled[] = $row;

            $id = $row['id'] ?? null;
            if ([] !== $pending && is_scalar($id)) {
                $repoint[(string) $id] = $pending;
            }
        }

        $count = $this->tables->restoreRows($table, $nulled);

        foreach ($repoint as $id => $columns) {
            $this->tables->updateRow($table, $id, $columns);
        }

        return $count;
    }
}
