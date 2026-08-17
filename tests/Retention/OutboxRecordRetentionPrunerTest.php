<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Retention;

use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\CoreModule\Retention\OutboxRecordRetentionPruner;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Pins the F7 outbox's entry into the unified retention seam: it prunes
 * PUBLISHED rows at `now − published_retention_days`, previews without deleting,
 * and a non-positive window disables the sweep (misconfig can't wipe live data).
 *
 * @covers \CoolMS\CoreModule\Retention\OutboxRecordRetentionPruner
 */
final class OutboxRecordRetentionPrunerTest extends TestCase
{
    #[Test]
    public function itPrunesPublishedRowsAtTheConfiguredCutoff(): void
    {
        $clock = new MockClock('2026-06-30 12:00:00');
        $outbox = $this->createMock(OutboxRelayRepositoryInterface::class);
        $outbox->expects(self::once())
            ->method('deletePublishedOlderThan')
            ->with(new DateTimeImmutable('2026-06-23 12:00:00')) // now − 7 days
            ->willReturn(5);

        $pruner = new OutboxRecordRetentionPruner($outbox, $clock, 7);

        self::assertSame('core.outbox', $pruner->retentionKey());
        self::assertSame('Delivered outbox rows', $pruner->retentionLabel());
        self::assertSame(5, $pruner->pruneExpired());
    }

    #[Test]
    public function itCountsPrunableRowsWithoutDeleting(): void
    {
        $clock = new MockClock('2026-06-30 12:00:00');
        $outbox = $this->createMock(OutboxRelayRepositoryInterface::class);
        $outbox->expects(self::never())->method('deletePublishedOlderThan');
        $outbox->expects(self::once())
            ->method('countPublishedOlderThan')
            ->with(new DateTimeImmutable('2026-06-23 12:00:00'))
            ->willReturn(3);

        self::assertSame(3, new OutboxRecordRetentionPruner($outbox, $clock, 7)->countExpired());
    }

    #[Test]
    public function aNonPositiveWindowDisablesTheSweep(): void
    {
        $outbox = $this->createMock(OutboxRelayRepositoryInterface::class);
        $outbox->expects(self::never())->method('deletePublishedOlderThan');
        $outbox->expects(self::never())->method('countPublishedOlderThan');

        $pruner = new OutboxRecordRetentionPruner($outbox, new MockClock(), 0);

        self::assertSame(0, $pruner->pruneExpired());
        self::assertSame(0, $pruner->countExpired());
    }
}
