<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Retention;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\Core\Retention\RetentionPrunerInterface;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Bounds the F7 consumer-idempotency inbox table: the inbox stores one
 * row per processed message so a redelivery is a no-op dedup, and a processed row
 * is only needed while a replay is still possible — after that it just accumulates.
 * {@see \CoolMS\CoreModule\Outbox\OutboxMaintenanceService} already prunes it via
 * the standalone `coolms:outbox:prune` command, but the F7 rails were absent from
 * the platform's UNIFIED retention seam ({@see RetentionPrunerInterface} → the
 * `coolms:retention:prune` command + the `retention.prune` scheduled handler) — so
 * a deploy that only cron-wires the aggregate sweep left this table growing. This
 * pruner plugs it in; the standalone command stays as a parallel entry point.
 *
 * Aged by the processed cutoff (`now − processed_retention_days`, default 30). The
 * window MUST stay LONGER than the longest redelivery horizon, or a late replay
 * could be reprocessed — hence a much longer default than the outbox's (which is
 * merely done). A non-positive window DISABLES the sweep (the
 * misconfig-can't-wipe-live-data guard), matching
 * the scheduler module's run-retention pruner. Reuses
 * `%coolms_core.inbox.processed_retention_days%` so the two entry points can never
 * disagree on the window. Auto-tagged `coolms.retention.pruner` via Core
 * autoconfiguration — zero DI wiring.
 */
final readonly class InboxRecordRetentionPruner implements RetentionPrunerInterface
{
    public function __construct(
        private ProcessedMessageStoreInterface $inbox,
        private ClockInterface $clock,
        #[Autowire('%coolms_core.inbox.processed_retention_days%')]
        private int $retentionDays,
    ) {
    }

    public function retentionKey(): string
    {
        return 'core.inbox';
    }

    public function retentionLabel(): string
    {
        return 'Processed inbox records';
    }

    public function pruneExpired(): int
    {
        if ($this->retentionDays < 1) {
            return 0;
        }

        return $this->inbox->deleteProcessedOlderThan($this->cutoff());
    }

    public function countExpired(): int
    {
        if ($this->retentionDays < 1) {
            return 0;
        }

        return $this->inbox->countProcessedOlderThan($this->cutoff());
    }

    private function cutoff(): DateTimeImmutable
    {
        return $this->clock->now()->sub(new DateInterval(sprintf('P%dD', $this->retentionDays)));
    }
}
