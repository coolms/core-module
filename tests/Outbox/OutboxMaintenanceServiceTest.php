<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Outbox;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\CoreModule\Outbox\OutboxMaintenanceService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The F7 retention service: prunes each table at `now − retentionDays`, and a
 * non-positive window disables that table's prune (so a misconfig can't wipe
 * live data).
 */
final class OutboxMaintenanceServiceTest extends TestCase
{
    #[Test]
    public function itPrunesEachTableAtItsConfiguredCutoff(): void
    {
        $clock = new MockClock('2026-06-30 12:00:00');
        $outbox = $this->outbox(removed: 5);
        $inbox = $this->inbox(removed: 3);

        $result = new OutboxMaintenanceService($outbox, $inbox, $clock, 7, 30)->prune();

        self::assertSame(['outbox' => 5, 'inbox' => 3], $result);
        // now − 7 days / now − 30 days, time-of-day preserved.
        self::assertEquals(new DateTimeImmutable('2026-06-23 12:00:00'), $outbox->cutoff);
        self::assertEquals(new DateTimeImmutable('2026-05-31 12:00:00'), $inbox->cutoff);
    }

    #[Test]
    public function aNonPositiveWindowDisablesThatTablesPrune(): void
    {
        $outbox = $this->outbox(removed: 5);
        $inbox = $this->inbox(removed: 3);

        $result = new OutboxMaintenanceService($outbox, $inbox, new MockClock(), 0, 0)->prune();

        self::assertSame(['outbox' => 0, 'inbox' => 0], $result);
        self::assertNull($outbox->cutoff, 'a disabled window must not call delete');
        self::assertNull($inbox->cutoff);
    }

    #[Test]
    public function itCountsPrunableRowsFromEachTableWithoutDeleting(): void
    {
        $clock = new MockClock('2026-06-30 12:00:00');
        $outbox = $this->outbox(removed: 5);
        $inbox = $this->inbox(removed: 3);

        $prunable = new OutboxMaintenanceService($outbox, $inbox, $clock, 7, 30)->countPrunable();

        self::assertSame(['outbox' => 5, 'inbox' => 3], $prunable);
        // countPrunable() previews at the SAME cutoffs prune() would delete at…
        self::assertEquals(new DateTimeImmutable('2026-06-23 12:00:00'), $outbox->countCutoff);
        self::assertEquals(new DateTimeImmutable('2026-05-31 12:00:00'), $inbox->countCutoff);
        // …and must never delete anything.
        self::assertNull($outbox->cutoff, 'countPrunable must not call deletePublishedOlderThan');
        self::assertNull($inbox->cutoff, 'countPrunable must not call deleteProcessedOlderThan');
    }

    #[Test]
    public function aNonPositiveWindowReportsZeroPrunable(): void
    {
        $outbox = $this->outbox(removed: 5);
        $inbox = $this->inbox(removed: 3);

        $prunable = new OutboxMaintenanceService($outbox, $inbox, new MockClock(), 0, 0)->countPrunable();

        self::assertSame(['outbox' => 0, 'inbox' => 0], $prunable);
        self::assertNull($outbox->countCutoff, 'a disabled window must not call count');
        self::assertNull($inbox->countCutoff);
    }

    /**
     * @return OutboxRelayRepositoryInterface&object{cutoff: ?DateTimeImmutable, countCutoff: ?DateTimeImmutable}
     */
    private function outbox(int $removed): OutboxRelayRepositoryInterface
    {
        return new class($removed) implements OutboxRelayRepositoryInterface {
            public ?DateTimeImmutable $cutoff = null;
            public ?DateTimeImmutable $countCutoff = null;

            public function __construct(private int $removed)
            {
            }

            public function claimUnpublished(int $limit): array
            {
                return [];
            }

            public function markPublished(string $outboxId): void
            {
            }

            public function markFailed(string $outboxId): void
            {
            }

            public function deletePublishedOlderThan(DateTimeImmutable $cutoff): int
            {
                $this->cutoff = $cutoff;

                return $this->removed;
            }

            public function countPublishedOlderThan(DateTimeImmutable $cutoff): int
            {
                $this->countCutoff = $cutoff;

                return $this->removed;
            }
        };
    }

    /**
     * @return ProcessedMessageStoreInterface&object{cutoff: ?DateTimeImmutable, countCutoff: ?DateTimeImmutable}
     */
    private function inbox(int $removed): ProcessedMessageStoreInterface
    {
        return new class($removed) implements ProcessedMessageStoreInterface {
            public ?DateTimeImmutable $cutoff = null;
            public ?DateTimeImmutable $countCutoff = null;

            public function __construct(private int $removed)
            {
            }

            public function firstSeen(string $consumer, string $messageId): bool
            {
                return true;
            }

            public function firstSeenRef(string $consumer, string $messageId, string $ref): ?string
            {
                return null; // Always a first claim — this fixture only exercises pruning.
            }

            public function hasProcessed(string $consumer, string $messageId): bool
            {
                return false;
            }

            public function refFor(string $consumer, string $messageId): ?string
            {
                return null;
            }

            public function deleteProcessedOlderThan(DateTimeImmutable $cutoff): int
            {
                $this->cutoff = $cutoff;

                return $this->removed;
            }

            public function countProcessedOlderThan(DateTimeImmutable $cutoff): int
            {
                $this->countCutoff = $cutoff;

                return $this->removed;
            }
        };
    }
}
