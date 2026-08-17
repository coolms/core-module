<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Backup;

use CoolMS\Core\Backup\BackupContributorInterface;
use CoolMS\Core\Backup\DefersRestoreColumnsInterface;
use CoolMS\CoreModule\Backup\BackupTableRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CoolMS\CoreModule\Backup\BackupTableRegistry
 */
final class BackupTableRegistryTest extends TestCase
{
    #[Test]
    public function aggregatesEveryContributorsTablesDeDupedAndSorted(): void
    {
        // Deliberately unsorted, and 'coolms_shared' declared by BOTH contributors.
        $registry = new BackupTableRegistry([
            $this->contributor(['coolms_b', 'coolms_a', 'coolms_shared']),
            $this->contributor(['coolms_shared', 'coolms_c']),
        ]);

        self::assertSame(['coolms_a', 'coolms_b', 'coolms_c', 'coolms_shared'], $registry->allTables());
    }

    #[Test]
    public function coversOnlyTablesSomeContributorExports(): void
    {
        $registry = new BackupTableRegistry([
            $this->contributor(['coolms_calendar_items', 'coolms_identity_users']),
        ]);

        self::assertTrue($registry->covers('coolms_calendar_items'));
        self::assertTrue($registry->covers('coolms_identity_users'));
        // A runtime table no contributor exports, and the feed's own table, are NOT synced.
        self::assertFalse($registry->covers('coolms_workflow_process_instances'));
        self::assertFalse($registry->covers('coolms_sync_changes'));
    }

    #[Test]
    public function emptyWhenThereAreNoContributors(): void
    {
        $registry = new BackupTableRegistry([]);

        self::assertSame([], $registry->allTables());
        self::assertFalse($registry->covers('coolms_anything'));
    }

    /**
     * The FK-safe order the sync applier writes in: contributors topo-sorted on
     * `restoreAfter()`, and within each, its own declared `tables()` order.
     *
     * The fixture is the real hazard in miniature: `calendar` sorts BEFORE `identity`
     * alphabetically, so only the `restoreAfter` edge can put users first — which is
     * exactly the bug that was found in a shipped contributor.
     */
    #[Test]
    public function ordersTablesByContributorDependencyThenDeclaredOrder(): void
    {
        $registry = new BackupTableRegistry([
            $this->contributor(['coolms_calendar_calendars', 'coolms_calendar_items'], 'calendar', ['identity']),
            $this->contributor(['coolms_identity_groups', 'coolms_identity_users'], 'identity'),
        ]);

        self::assertSame([
            // identity first (calendar declared restoreAfter), groups before users (declared).
            'coolms_identity_groups',
            'coolms_identity_users',
            'coolms_calendar_calendars',
            'coolms_calendar_items',
        ], $registry->orderedTables());
    }

    #[Test]
    public function sortsAGivenTableSetIntoRestoreOrderAndParksUnknownsLast(): void
    {
        $registry = new BackupTableRegistry([
            $this->contributor(['coolms_calendar_calendars', 'coolms_calendar_items'], 'calendar', ['identity']),
            $this->contributor(['coolms_identity_users'], 'identity'),
        ]);

        // Deliberately worst-case input order, plus a table no contributor owns.
        $sorted = $registry->sortByRestoreOrder([
            'coolms_calendar_items',
            'coolms_not_synced',
            'coolms_identity_users',
            'coolms_calendar_calendars',
        ]);

        self::assertSame([
            'coolms_identity_users',
            'coolms_calendar_calendars',
            'coolms_calendar_items',
            // Unknown to the registry → parked at the end rather than silently ranked first.
            'coolms_not_synced',
        ], $sorted);
    }

    #[Test]
    public function exposesDeferredColumnsOnlyForContributorsThatDeclareThem(): void
    {
        $deferring = $this->createStub(DeferringContributor::class);
        $deferring->method('backupKey')->willReturn('calendar');
        $deferring->method('restoreAfter')->willReturn([]);
        $deferring->method('tables')->willReturn(['coolms_calendar_calendars']);
        $deferring->method('deferredRestoreColumns')->willReturn([
            'coolms_calendar_calendars' => ['parent_id', 'holiday_calendar_id'],
        ]);

        $registry = new BackupTableRegistry([
            $deferring,
            $this->contributor(['coolms_identity_users'], 'identity'),
        ]);

        self::assertSame(['parent_id', 'holiday_calendar_id'], $registry->deferredColumnsFor('coolms_calendar_calendars'));
        // A contributor that doesn't implement the opt-in interface defers nothing.
        self::assertSame([], $registry->deferredColumnsFor('coolms_identity_users'));
        self::assertSame([], $registry->deferredColumnsFor('coolms_unknown'));
    }

    /**
     * @param list<string> $tables
     * @param list<string> $restoreAfter
     */
    private function contributor(array $tables, string $key = 'x', array $restoreAfter = []): BackupContributorInterface
    {
        $contributor = $this->createStub(BackupContributorInterface::class);
        $contributor->method('tables')->willReturn($tables);
        $contributor->method('backupKey')->willReturn($key);
        $contributor->method('restoreAfter')->willReturn($restoreAfter);

        return $contributor;
    }
}

/**
 * A contributor that also declares deferred columns — the shape
 * {@see BackupTableRegistry::deferredColumnsFor()} reads. Named so PHPUnit can stub
 * both interfaces at once.
 */
interface DeferringContributor extends BackupContributorInterface, DefersRestoreColumnsInterface
{
}
