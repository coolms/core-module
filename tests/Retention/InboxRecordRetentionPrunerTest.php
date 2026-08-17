<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Retention;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\CoreModule\Retention\InboxRecordRetentionPruner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Pins the F7 inbox's entry into the unified retention seam: it prunes
 * PROCESSED idempotency rows at `now − processed_retention_days`, previews without
 * deleting, and a non-positive window disables the sweep (misconfig can't wipe the
 * dedup log while a replay is still possible).
 *
 * @covers \CoolMS\CoreModule\Retention\InboxRecordRetentionPruner
 */
final class InboxRecordRetentionPrunerTest extends TestCase
{
    #[Test]
    public function itPrunesProcessedRowsAtTheConfiguredCutoff(): void
    {
        $clock = new MockClock('2026-06-30 12:00:00');
        $inbox = $this->createMock(ProcessedMessageStoreInterface::class);
        $inbox->expects(self::once())
            ->method('deleteProcessedOlderThan')
            ->with(new DateTimeImmutable('2026-05-31 12:00:00')) // now − 30 days
            ->willReturn(4);

        $pruner = new InboxRecordRetentionPruner($inbox, $clock, 30);

        self::assertSame('core.inbox', $pruner->retentionKey());
        self::assertSame('Processed inbox records', $pruner->retentionLabel());
        self::assertSame(4, $pruner->pruneExpired());
    }

    #[Test]
    public function itCountsPrunableRowsWithoutDeleting(): void
    {
        $clock = new MockClock('2026-06-30 12:00:00');
        $inbox = $this->createMock(ProcessedMessageStoreInterface::class);
        $inbox->expects(self::never())->method('deleteProcessedOlderThan');
        $inbox->expects(self::once())
            ->method('countProcessedOlderThan')
            ->with(new DateTimeImmutable('2026-05-31 12:00:00'))
            ->willReturn(2);

        self::assertSame(2, new InboxRecordRetentionPruner($inbox, $clock, 30)->countExpired());
    }

    #[Test]
    public function aNonPositiveWindowDisablesTheSweep(): void
    {
        $inbox = $this->createMock(ProcessedMessageStoreInterface::class);
        $inbox->expects(self::never())->method('deleteProcessedOlderThan');
        $inbox->expects(self::never())->method('countProcessedOlderThan');

        $pruner = new InboxRecordRetentionPruner($inbox, new MockClock(), 0);

        self::assertSame(0, $pruner->pruneExpired());
        self::assertSame(0, $pruner->countExpired());
    }
}
