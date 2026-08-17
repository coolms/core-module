<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupException;
use CoolMS\Core\Backup\BackupReaderInterface;
use CoolMS\Core\Backup\TableBackupPortInterface;

use function array_map;
use function array_values;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function is_file;
use function is_scalar;
use function json_decode;
use function preg_match;
use function sprintf;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * The read handle a {@see \CoolMS\Core\Backup\BackupContributorInterface}
 * receives during `import`. The mirror of {@see BackupWriter}: scoped to ONE
 * contributor, reading from `<bundle>/data/<backupKey>/`. `loadTable(...)`
 * upserts a whole table (delete-by-id + insert, idempotent).
 *
 * Created per contributor by {@see BackupRunner}; not a DI service.
 */
final readonly class BackupReader implements BackupReaderInterface
{
    /** Content hashes are lowercase-hex sha256; validate before using one as a path segment. */
    private const string HASH_PATTERN = '/^[0-9a-f]{4,}$/';

    /** Blob namespaces come from contributor code (e.g. 'blobs', 'public-blobs'); validate anyway. */
    private const string NAMESPACE_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public function __construct(
        private string $bundleDir,
        private string $backupKey,
        private TableBackupPortInterface $tables,
    ) {
    }

    /**
     * Restore a table from `data/<key>/<table>.json` (idempotent upsert);
     * returns rows restored. A payload that was absent (never exported) is a
     * no-op — a config-only bundle legitimately omits runtime tables.
     */
    public function loadTable(string $table): int
    {
        return $this->tables->restoreRows($table, $this->readRows($table));
    }

    /**
     * Restore `$table` like {@see loadTable()}, but with every column in
     * `$deferredColumns` NULLed on insert and re-applied afterwards via a targeted
     * {@see updateRow()}. This is the safe way to restore a table with a
     * SELF-REFERENTIAL FK (`parent_id` → the same table).
     *
     * **A plain {@see loadTable()} on a self-ref table corrupts data SILENTLY.**
     * `restoreRows` is delete-by-id + insert per row, so if child B is restored
     * before parent A, B is inserted pointing at the OLD A — and then A's delete
     * fires A's FK rule against the row that just landed:
     *  - `ON DELETE SET NULL` → **B.parent_id is quietly set to NULL** (B survives,
     *    reparented to nothing);
     *  - `ON DELETE CASCADE` → **B is quietly deleted**, and its turn has passed, so
     *    nothing puts it back.
     * Both outcomes are a successful-looking restore that lost the hierarchy. Neither
     * throws. That is exactly the silent drift this seam exists to prevent.
     *
     * **Why deferral rather than a parent-first sort.** A sort works (see
     * `VfsBackupContributor::orderNodesParentFirst()`), but it must find a valid
     * order, and one does not always exist: two self-ref columns on one table can
     * reference each other across rows (calendar A's holiday calendar is B, B's
     * parent is A) — a genuine cycle in the combined graph with no legal insert
     * order. Nulling the FK removes every edge, so insert order stops mattering at
     * all; the re-point then succeeds because all rows exist. Same shape as the
     * circular-FK dance in {@see updateRow()}, generalised.
     *
     * **The deferred set must be NULLABLE and CHECK-COHERENT.** Nullable is the
     * obvious half. The other half cost a real failure while building this: a CHECK
     * can couple an FK to a column that is not one, and nulling half of such a pair
     * produces a row the table rejects outright. `coolms_calendar_items` has exactly
     * that — `chk_item_override_shape` demands `parent_item_id` and
     * `recurrence_instant` be null together or set together — so deferring only the
     * FK turns every recurrence override into a constraint violation. The fix is to
     * defer the whole coupled GROUP; a single `updateRow()` then restores them in one
     * statement, so the CHECK never sees a half-applied row. Before deferring a
     * column, read the table's CHECKs, not just its FKs.
     *
     * A row whose deferred columns are all null costs no update.
     *
     * @param list<string> $deferredColumns
     */
    public function loadTableDeferring(string $table, array $deferredColumns): int
    {
        $nulled = [];
        /** @var array<string, array<string, mixed>> $repoint */
        $repoint = [];

        foreach ($this->readRows($table) as $row) {
            $pending = [];
            foreach ($deferredColumns as $column) {
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

        $records = $this->tables->restoreRows($table, $nulled);

        // Every row is in place now, so each reference resolves regardless of order.
        foreach ($repoint as $id => $columns) {
            $this->tables->updateRow($table, $id, $columns);
        }

        return $records;
    }

    /**
     * Read a table's rows without restoring — for a contributor that must
     * transform them first (e.g. VFS nodes need a parent-first topological sort
     * before insert because of the self-referential FK). Absent payload → `[]`.
     *
     * @return list<array<int|string, mixed>>
     */
    public function readRows(string $table): array
    {
        $path = $this->payloadPath($table);
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new BackupException(sprintf('Backup payload for table "%s" (contributor "%s") is not a JSON array.', $table, $this->backupKey));
        }

        // Build the list explicitly so the shape is provable (json_decode is mixed).
        $rows = [];
        foreach ($decoded as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Restore a pre-built row list into `$table` (idempotent upsert). Pairs with
     * {@see readRows()} for contributors that reorder/filter before inserting.
     *
     * @param list<array<int|string, mixed>> $rows
     */
    public function restoreRows(string $table, array $rows): int
    {
        return $this->tables->restoreRows($table, $rows);
    }

    /**
     * Set specific columns on ONE already-restored row (targeted UPDATE by id,
     * no delete). How a contributor closes a circular FK: restore the parent with
     * the cycle-closing column NULLed via {@see restoreRows()}, restore the rows
     * it points at, then point it back with this — a second {@see restoreRows()}
     * can't, its delete-by-id would CASCADE-wipe the rows just inserted.
     *
     * @param array<string, mixed> $columns
     */
    public function updateRow(string $table, int|string $id, array $columns): void
    {
        $this->tables->updateRow($table, $id, $columns);
    }

    /**
     * Delete-reconcile `$table`: remove the live rows ABSENT from this bundle's
     * snapshot (the rows deleted on the source since the target last synced).
     * Reads the snapshot's `$idColumn` set, diffs it against the
     * live ids (scoped by `$scopeEquals`), and deletes the surplus. Returns rows
     * deleted — or, when `$dryRun`, the number that WOULD be.
     *
     * **SAFETY — never wipes an un-exported table.** If the bundle carries NO
     * payload for `$table` (the contributor didn't export it, e.g. a config-only
     * bundle omitting a runtime table), this is a NO-OP. A missing payload means
     * "unknown", not "the source has zero rows" — treating it as the latter would
     * empty a live table. A present-but-empty payload DOES reconcile to zero (the
     * source legitimately has none).
     *
     * `$scopeEquals` scopes the live set to the SAME filter the contributor
     * exported with (e.g. `['source' => 'vfs']`), so rows the contributor never
     * exports — module-shipped definitions, other partitions — are never touched.
     *
     * @param array<string, scalar> $scopeEquals
     */
    public function reconcileTable(string $table, bool $dryRun, array $scopeEquals = [], string $idColumn = 'id'): int
    {
        if (!is_file($this->payloadPath($table))) {
            return 0;
        }

        $keep = [];
        foreach ($this->readRows($table) as $row) {
            $id = $row[$idColumn] ?? null;
            if (is_scalar($id)) {
                $keep[(string) $id] = true;
            }
        }

        $stale = [];
        foreach ($this->tables->liveIds($table, $idColumn, $scopeEquals) as $liveId) {
            if (!isset($keep[$liveId])) {
                $stale[] = $liveId;
            }
        }

        if ($dryRun || [] === $stale) {
            return count($stale);
        }

        return $this->tables->deleteByIds($table, $idColumn, $stale);
    }

    /**
     * Composite-key delete-reconcile for a table whose PK is MULTIPLE columns and
     * has no single `id` — a join table like `user_groups(user_id, group_id)`. Same
     * contract + the same absent-payload-is-a-no-op safety guard as
     * {@see reconcileTable()}, but diffs on the ordered `$keyColumns` tuple instead
     * of one id column.
     *
     * @param list<string>          $keyColumns
     * @param array<string, scalar> $scopeEquals
     */
    public function reconcileCompositeTable(string $table, array $keyColumns, bool $dryRun, array $scopeEquals = []): int
    {
        if (!is_file($this->payloadPath($table))) {
            return 0;
        }

        $keep = [];
        foreach ($this->readRows($table) as $row) {
            $composite = $this->compositeKeyString($row, $keyColumns);
            if (null !== $composite) {
                $keep[$composite] = true;
            }
        }

        $stale = [];
        foreach ($this->tables->liveCompositeKeys($table, $keyColumns, $scopeEquals) as $liveKey) {
            $composite = $this->compositeKeyString($liveKey, $keyColumns);
            if (null !== $composite && !isset($keep[$composite])) {
                $stale[] = $liveKey;
            }
        }

        if ($dryRun || [] === $stale) {
            return count($stale);
        }

        return $this->tables->deleteByCompositeKeys($table, $keyColumns, $stale);
    }

    /**
     * Delete-reconcile `$table` restricted to a WHITELIST of group keys — only live
     * rows whose `$membershipColumn` is IN `$allowedGroupKeys` are candidates. The
     * grouped sibling of {@see reconcileTable()}: for a partitioned export where the
     * kept partition can't be expressed as a single-column equality (the Definition
     * ladder excludes a whole definition if ANY of its versions is module-owned), the
     * contributor computes the allowed group keys once and this reconciles each table
     * in ONE pass — reading the snapshot payload ONCE (not once per group, which is
     * O(groups²) in payload parsing). Same absent-payload-is-a-no-op guard as
     * {@see reconcileTable()}; an EMPTY whitelist is a no-op (fail-safe: a mis-read
     * universe reconciles nothing, degrading to additive-only). Diffs the whitelisted
     * live ids against the snapshot's `$idColumn` set and deletes the surplus. For the
     * ladder's header table pass `$membershipColumn === $idColumn` (`'id'`).
     *
     * @param list<string> $allowedGroupKeys
     */
    public function reconcileTableWithinGroups(string $table, string $membershipColumn, array $allowedGroupKeys, bool $dryRun, string $idColumn = 'id'): int
    {
        if (!is_file($this->payloadPath($table)) || [] === $allowedGroupKeys) {
            return 0;
        }

        $keep = [];
        foreach ($this->readRows($table) as $row) {
            $id = $row[$idColumn] ?? null;
            if (is_scalar($id)) {
                $keep[(string) $id] = true;
            }
        }

        $stale = [];
        foreach ($this->tables->liveIdsWhereIn($table, $idColumn, $membershipColumn, $allowedGroupKeys) as $liveId) {
            if (!isset($keep[$liveId])) {
                $stale[] = $liveId;
            }
        }

        if ($dryRun || [] === $stale) {
            return count($stale);
        }

        return $this->tables->deleteByIds($table, $idColumn, $stale);
    }

    /**
     * The DISTINCT live values of `$column` in `$table` matching `$scopeEquals` — a
     * read-only helper for a contributor that must compute a CROSS-TABLE reconcile
     * universe before delete-reconciling. The Definition ladder uses it to find the
     * definition_ids that own a `source='contributor'` version (the module-shipped
     * ladders it must never reconcile) and the full live definition-id set. A thin,
     * de-duplicated pass-through to the live-id query; reads the live DB, touches no
     * bundle payload.
     *
     * @param array<string, scalar> $scopeEquals
     *
     * @return list<string>
     */
    public function liveValues(string $table, string $column, array $scopeEquals = []): array
    {
        $seen = [];
        foreach ($this->tables->liveIds($table, $column, $scopeEquals) as $value) {
            $seen[$value] = $value;
        }

        return array_values($seen);
    }

    /**
     * The sha256 hashes of every blob this contributor exported under
     * `$namespace` (scans `data/<key>/<namespace>/aa/bb/<hash>`). Empty when the
     * contributor stored none.
     *
     * @return list<string>
     */
    public function blobHashes(string $namespace = 'blobs'): array
    {
        $this->assertNamespace($namespace);
        $matches = glob(sprintf('%s/data/%s/%s/*/*/*', $this->bundleDir, $this->backupKey, $namespace));
        if (false === $matches) {
            return [];
        }

        return array_map(basename(...), $matches);
    }

    /** Read one exported blob's raw bytes (throws if the payload is missing). */
    public function readBlob(string $hash, string $namespace = 'blobs'): string
    {
        $path = $this->blobPath($hash, $namespace);
        if (!is_file($path)) {
            throw new BackupException(sprintf('Backup blob "%s" (contributor "%s", namespace "%s") is missing from the bundle.', $hash, $this->backupKey, $namespace));
        }

        return (string) file_get_contents($path);
    }

    private function payloadPath(string $name): string
    {
        return sprintf('%s/data/%s/%s.json', $this->bundleDir, $this->backupKey, $name);
    }

    /**
     * A stable, order-sensitive string for a composite key — the `$keyColumns`
     * values joined by a control char (US, `\x1f`) that cannot occur in a UUID.
     * Returns null if any key component is missing or non-scalar, so a malformed
     * row is skipped rather than colliding into a bogus key.
     *
     * @param array<int|string, mixed> $row
     * @param list<string>             $keyColumns
     */
    private function compositeKeyString(array $row, array $keyColumns): ?string
    {
        $parts = [];
        foreach ($keyColumns as $column) {
            $value = $row[$column] ?? null;
            if (!is_scalar($value)) {
                return null;
            }
            $parts[] = (string) $value;
        }

        return implode("\x1f", $parts);
    }

    private function blobPath(string $hash, string $namespace): string
    {
        if (1 !== preg_match(self::HASH_PATTERN, $hash)) {
            throw new BackupException(sprintf('Refusing to read blob with unsafe hash "%s".', $hash));
        }
        $this->assertNamespace($namespace);

        return sprintf('%s/data/%s/%s/%s/%s/%s', $this->bundleDir, $this->backupKey, $namespace, substr($hash, 0, 2), substr($hash, 2, 2), $hash);
    }

    private function assertNamespace(string $namespace): void
    {
        if (1 !== preg_match(self::NAMESPACE_PATTERN, $namespace)) {
            throw new BackupException(sprintf('Refusing to use unsafe blob namespace "%s".', $namespace));
        }
    }
}
