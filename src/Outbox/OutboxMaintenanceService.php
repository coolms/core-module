<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Outbox;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Keeps the F7 rails' tables bounded: prunes DELIVERED outbox rows and
 * old idempotency-inbox rows past their retention windows. Without it both tables
 * grow forever — the relay never re-reads a published row, and a processed-inbox
 * row is only needed while a replay is still possible.
 *
 * Two SEPARATE windows: published outbox rows can go fairly soon (they're done),
 * but the inbox window MUST stay longer than the longest redelivery horizon, or a
 * late replay could be reprocessed. A non-positive window disables that table's
 * prune (a guard so a misconfig can never wipe live data).
 */
final readonly class OutboxMaintenanceService
{
    public function __construct(
        private OutboxRelayRepositoryInterface $outbox,
        private ProcessedMessageStoreInterface $inbox,
        private ClockInterface $clock,
        #[Autowire('%coolms_core.outbox.published_retention_days%')]
        private int $outboxRetentionDays,
        #[Autowire('%coolms_core.inbox.processed_retention_days%')]
        private int $inboxRetentionDays,
    ) {
    }

    /**
     * @return array{outbox: int, inbox: int} rows pruned per table
     */
    public function prune(): array
    {
        return [
            'outbox' => $this->outboxRetentionDays < 1
                ? 0
                : $this->outbox->deletePublishedOlderThan($this->cutoff($this->outboxRetentionDays)),
            'inbox' => $this->inboxRetentionDays < 1
                ? 0
                : $this->inbox->deleteProcessedOlderThan($this->cutoff($this->inboxRetentionDays)),
        ];
    }

    /**
     * How many rows the next {@see prune()} would remove from each table, WITHOUT
     * deleting anything — the read-only preview backing `coolms:outbox:prune
     * --dry-run` (0 per table when that table's window is disabled). Computes the
     * SAME cutoffs as {@see prune()} so the preview can never disagree with the
     * delete it previews.
     *
     * @return array{outbox: int, inbox: int}
     */
    public function countPrunable(): array
    {
        return [
            'outbox' => $this->outboxRetentionDays < 1
                ? 0
                : $this->outbox->countPublishedOlderThan($this->cutoff($this->outboxRetentionDays)),
            'inbox' => $this->inboxRetentionDays < 1
                ? 0
                : $this->inbox->countProcessedOlderThan($this->cutoff($this->inboxRetentionDays)),
        ];
    }

    public function outboxRetentionDays(): int
    {
        return $this->outboxRetentionDays;
    }

    public function inboxRetentionDays(): int
    {
        return $this->inboxRetentionDays;
    }

    private function cutoff(int $days): DateTimeImmutable
    {
        // Compute the cutoff; never trust the raw count into a DateInterval string.
        return $this->clock->now()->sub(new DateInterval(sprintf('P%dD', $days)));
    }
}
