<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Backup;

use CoolMS\Core\Backup\BackupReaderInterface;
use CoolMS\Core\Backup\TableBackupPortInterface;
use CoolMS\CoreModule\Backup\BackupReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function bin2hex;
use function count;
use function json_encode;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;

/**
 * @covers \CoolMS\CoreModule\Backup\BackupReaderInterface::reconcileTable
 * @covers \CoolMS\CoreModule\Backup\BackupReaderInterface::reconcileCompositeTable
 * @covers \CoolMS\CoreModule\Backup\BackupReaderInterface::reconcileTableWithinGroups
 * @covers \CoolMS\CoreModule\Backup\BackupReaderInterface::liveValues
 */
final class BackupReaderReconcileTest extends TestCase
{
    private const string KEY = 'sso';

    private const string TABLE = 'coolms_sso_identities';

    /** A join table with a COMPOSITE PK (no single id) — the composite-reconcile case. */
    private const string COMPOSITE_TABLE = 'coolms_identity_user_groups';

    /** @var list<string> */
    private const array COMPOSITE_KEY = ['user_id', 'group_id'];

    private string $dir;
    private Filesystem $fs;

    #[Test]
    public function anAbsentPayloadIsANoOpAndNeverTouchesTheLiveTable(): void
    {
        // No payload written for the table → the bundle says nothing about it.
        $port = new RecordingReconcilePort(['a', 'b', 'c']);

        $deleted = $this->reader($port)->reconcileTable(self::TABLE, false);

        self::assertSame(0, $deleted);
        self::assertFalse($port->liveIdsCalled, 'a missing payload must not even query the live table');
        self::assertNull($port->deletedIds, 'a missing payload must never delete — "unknown" is not "zero rows"');
    }

    #[Test]
    public function deletesTheLiveRowsAbsentFromTheSnapshot(): void
    {
        $this->writePayload(self::TABLE, [['id' => 'a'], ['id' => 'b']]);
        // Live has two extra rows (c, d) not in the snapshot → both are stale.
        $port = new RecordingReconcilePort(['a', 'b', 'c', 'd']);

        $deleted = $this->reader($port)->reconcileTable(self::TABLE, false);

        self::assertSame(2, $deleted);
        self::assertSame(['c', 'd'], $port->deletedIds);
    }

    #[Test]
    public function aPresentButEmptyPayloadReconcilesToZeroRows(): void
    {
        // The source legitimately has none → every live row is stale (distinct from absent).
        $this->writePayload(self::TABLE, []);
        $port = new RecordingReconcilePort(['a', 'b']);

        $deleted = $this->reader($port)->reconcileTable(self::TABLE, false);

        self::assertSame(2, $deleted);
        self::assertSame(['a', 'b'], $port->deletedIds);
    }

    #[Test]
    public function dryRunCountsTheStaleRowsWithoutDeleting(): void
    {
        $this->writePayload(self::TABLE, [['id' => 'a']]);
        $port = new RecordingReconcilePort(['a', 'b', 'c']);

        $wouldDelete = $this->reader($port)->reconcileTable(self::TABLE, true);

        self::assertSame(2, $wouldDelete);
        self::assertNull($port->deletedIds, 'a dry run must never call deleteByIds');
    }

    #[Test]
    public function noStaleRowsSkipsTheDeleteEntirely(): void
    {
        $this->writePayload(self::TABLE, [['id' => 'a'], ['id' => 'b']]);
        $port = new RecordingReconcilePort(['a', 'b']);

        $deleted = $this->reader($port)->reconcileTable(self::TABLE, false);

        self::assertSame(0, $deleted);
        self::assertNull($port->deletedIds, 'nothing stale → deleteByIds is not called');
    }

    #[Test]
    public function passesTheScopeFilterThroughToTheLiveQuery(): void
    {
        $this->writePayload(self::TABLE, [['id' => 'a']]);
        $port = new RecordingReconcilePort(['a']);

        $this->reader($port)->reconcileTable(self::TABLE, true, ['source' => 'vfs']);

        self::assertSame(['source' => 'vfs'], $port->recordedScope, 'the export filter must scope the live set so un-owned rows are never considered');
    }

    #[Test]
    public function compositeReconcileDeletesTheLiveTuplesAbsentFromTheSnapshot(): void
    {
        // Snapshot keeps (u1,g1); live also has (u1,g2) + (u2,g1) → two stale tuples.
        $this->writePayload(self::COMPOSITE_TABLE, [['user_id' => 'u1', 'group_id' => 'g1']]);
        $port = new RecordingReconcilePort(liveCompositeKeys: [
            ['user_id' => 'u1', 'group_id' => 'g1'],
            ['user_id' => 'u1', 'group_id' => 'g2'],
            ['user_id' => 'u2', 'group_id' => 'g1'],
        ]);

        $deleted = $this->reader($port)->reconcileCompositeTable(self::COMPOSITE_TABLE, self::COMPOSITE_KEY, false);

        self::assertSame(2, $deleted);
        self::assertSame([
            ['user_id' => 'u1', 'group_id' => 'g2'],
            ['user_id' => 'u2', 'group_id' => 'g1'],
        ], $port->deletedCompositeKeys, 'exactly the tuples absent from the snapshot are deleted — diffed on the ORDERED (user_id, group_id) key');
    }

    #[Test]
    public function compositeReconcileHonoursTheKeyTupleNotJustOneColumn(): void
    {
        // (u1,g1) is kept; (u1,g2) shares user_id but is a DIFFERENT membership → stale.
        // A naive single-column (user_id) diff would wrongly keep it.
        $this->writePayload(self::COMPOSITE_TABLE, [['user_id' => 'u1', 'group_id' => 'g1']]);
        $port = new RecordingReconcilePort(liveCompositeKeys: [
            ['user_id' => 'u1', 'group_id' => 'g1'],
            ['user_id' => 'u1', 'group_id' => 'g2'],
        ]);

        $deleted = $this->reader($port)->reconcileCompositeTable(self::COMPOSITE_TABLE, self::COMPOSITE_KEY, false);

        self::assertSame(1, $deleted);
        self::assertSame([['user_id' => 'u1', 'group_id' => 'g2']], $port->deletedCompositeKeys);
    }

    #[Test]
    public function compositeReconcileAbsentPayloadIsANoOp(): void
    {
        $port = new RecordingReconcilePort(liveCompositeKeys: [['user_id' => 'u1', 'group_id' => 'g1']]);

        $deleted = $this->reader($port)->reconcileCompositeTable(self::COMPOSITE_TABLE, self::COMPOSITE_KEY, false);

        self::assertSame(0, $deleted);
        self::assertFalse($port->liveCompositeKeysCalled, 'a missing composite payload must not even query the live table');
        self::assertNull($port->deletedCompositeKeys);
    }

    #[Test]
    public function compositeReconcileDryRunCountsWithoutDeleting(): void
    {
        $this->writePayload(self::COMPOSITE_TABLE, [['user_id' => 'u1', 'group_id' => 'g1']]);
        $port = new RecordingReconcilePort(liveCompositeKeys: [
            ['user_id' => 'u1', 'group_id' => 'g1'],
            ['user_id' => 'u2', 'group_id' => 'g2'],
        ]);

        $wouldDelete = $this->reader($port)->reconcileCompositeTable(self::COMPOSITE_TABLE, self::COMPOSITE_KEY, true, ['source' => 'x']);

        self::assertSame(1, $wouldDelete);
        self::assertNull($port->deletedCompositeKeys, 'a dry run must never call deleteByCompositeKeys');
        self::assertSame(['source' => 'x'], $port->recordedCompositeScope, 'the scope filter threads through to the live composite query');
    }

    #[Test]
    public function groupedReconcileDeletesTheWhitelistedLiveRowsAbsentFromTheSnapshot(): void
    {
        // Snapshot keeps 'a'; the live whitelist (rows of allowed groups) is a,b,c → b,c are stale.
        $this->writePayload(self::TABLE, [['id' => 'a']]);
        $port = new RecordingReconcilePort(liveWhereIn: ['a', 'b', 'c']);

        $deleted = $this->reader($port)->reconcileTableWithinGroups(self::TABLE, 'definition_id', ['g1', 'g2'], false);

        self::assertSame(2, $deleted);
        self::assertSame(['b', 'c'], $port->deletedIds);
        self::assertSame('definition_id', $port->recordedMembershipColumn, 'the membership column (not the id column) scopes the live whitelist');
        self::assertSame(['g1', 'g2'], $port->recordedAllowed, 'the allowed group keys reach the live query in ONE call');
    }

    #[Test]
    public function groupedReconcileWithAnEmptyWhitelistIsANoOpAndNeverQueries(): void
    {
        $this->writePayload(self::TABLE, [['id' => 'a']]);
        $port = new RecordingReconcilePort(liveWhereIn: ['x']);

        $deleted = $this->reader($port)->reconcileTableWithinGroups(self::TABLE, 'definition_id', [], false);

        self::assertSame(0, $deleted);
        self::assertFalse($port->liveIdsWhereInCalled, 'an empty whitelist reconciles nothing — never even queries the live table (fail-safe)');
        self::assertNull($port->deletedIds);
    }

    #[Test]
    public function groupedReconcileAbsentPayloadIsANoOp(): void
    {
        $port = new RecordingReconcilePort(liveWhereIn: ['a', 'b']);

        $deleted = $this->reader($port)->reconcileTableWithinGroups(self::TABLE, 'definition_id', ['g1'], false);

        self::assertSame(0, $deleted);
        self::assertFalse($port->liveIdsWhereInCalled, 'a missing payload must not even query the live table');
        self::assertNull($port->deletedIds);
    }

    #[Test]
    public function groupedReconcileDryRunCountsWithoutDeleting(): void
    {
        $this->writePayload(self::TABLE, [['id' => 'a']]);
        $port = new RecordingReconcilePort(liveWhereIn: ['a', 'b']);

        $wouldDelete = $this->reader($port)->reconcileTableWithinGroups(self::TABLE, 'definition_id', ['g1'], true);

        self::assertSame(1, $wouldDelete);
        self::assertNull($port->deletedIds, 'a dry run within groups must never call deleteByIds');
    }

    #[Test]
    public function liveValuesReturnsTheDistinctLiveColumnValuesInFirstSeenOrder(): void
    {
        // The live read carries duplicates (many versions share one definition_id);
        // a reconcile universe must be a de-duplicated set.
        $port = new RecordingReconcilePort(['a', 'a', 'b', 'c', 'b']);

        self::assertSame(['a', 'b', 'c'], $this->reader($port)->liveValues(self::TABLE, 'definition_id'));
    }

    #[Test]
    public function liveValuesThreadsTheScopeFilterToTheLiveQuery(): void
    {
        $port = new RecordingReconcilePort(['a']);

        $this->reader($port)->liveValues(self::TABLE, 'definition_id', ['source' => 'contributor']);

        self::assertSame(['source' => 'contributor'], $port->recordedScope, 'the scope filter (e.g. source=contributor → the module-owned definition ids) reaches the live query');
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->dir = sys_get_temp_dir() . '/coolms-reader-reconcile-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->dir);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writePayload(string $table, array $rows): void
    {
        $this->fs->dumpFile(
            sprintf('%s/data/%s/%s.json', $this->dir, self::KEY, $table),
            (string) json_encode($rows),
        );
    }

    private function reader(TableBackupPortInterface $port): BackupReaderInterface
    {
        return new BackupReader($this->dir, self::KEY, $port);
    }
}

/**
 * Records reconcile calls and returns canned live-id / live-composite-key sets — the
 * diff logic under test lives entirely in {@see BackupReaderInterface}, so the port is a pure spy.
 */
final class RecordingReconcilePort implements TableBackupPortInterface
{
    public bool $liveIdsCalled = false;
    public bool $liveCompositeKeysCalled = false;
    public bool $liveIdsWhereInCalled = false;

    /** @var array<string, scalar>|null */
    public ?array $recordedScope = null;

    /** @var array<string, scalar>|null */
    public ?array $recordedCompositeScope = null;

    public ?string $recordedMembershipColumn = null;

    /** @var list<string>|null */
    public ?array $recordedAllowed = null;

    /** @var list<string>|null */
    public ?array $deletedIds = null;

    /** @var list<array<string, string>>|null */
    public ?array $deletedCompositeKeys = null;

    /**
     * @param list<string>                $liveIds
     * @param list<array<string, string>> $liveCompositeKeys
     * @param list<string>                $liveWhereIn
     */
    public function __construct(
        private readonly array $liveIds = [],
        private readonly array $liveCompositeKeys = [],
        private readonly array $liveWhereIn = [],
    ) {
    }

    public function dumpTable(string $table): array
    {
        return [];
    }

    public function streamTable(string $table): iterable
    {
        return $this->dumpTable($table);
    }

    public function dumpRowsByIds(string $table, string $idColumn, array $ids): array
    {
        return []; // row-hydration (B.2.4) is not exercised by this reconcile double
    }

    public function restoreRows(string $table, array $rows): int
    {
        return 0;
    }

    public function updateRow(string $table, int|string $id, array $columns): void
    {
    }

    public function liveIds(string $table, string $idColumn, array $scopeEquals = []): array
    {
        $this->liveIdsCalled = true;
        $this->recordedScope = $scopeEquals;

        return $this->liveIds;
    }

    public function deleteByIds(string $table, string $idColumn, array $ids): int
    {
        $this->deletedIds = $ids;

        return count($ids);
    }

    public function liveIdsWhereIn(string $table, string $idColumn, string $membershipColumn, array $allowedValues): array
    {
        $this->liveIdsWhereInCalled = true;
        $this->recordedMembershipColumn = $membershipColumn;
        $this->recordedAllowed = $allowedValues;

        return $this->liveWhereIn;
    }

    public function liveCompositeKeys(string $table, array $keyColumns, array $scopeEquals = []): array
    {
        $this->liveCompositeKeysCalled = true;
        $this->recordedCompositeScope = $scopeEquals;

        return $this->liveCompositeKeys;
    }

    public function deleteByCompositeKeys(string $table, array $keyColumns, array $keys): int
    {
        $this->deletedCompositeKeys = $keys;

        return count($keys);
    }
}
