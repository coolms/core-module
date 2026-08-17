<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Retention;

use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\Core\Retention\RetentionPrunerInterface;
use DateInterval;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function sprintf;

/**
 * Bounds the F7 transactional-outbox table: the relay marks a row
 * PUBLISHED after dispatch and never re-reads it, so published rows accumulate
 * forever. {@see \CoolMS\CoreModule\Outbox\OutboxMaintenanceService} already
 * prunes them via the standalone `coolms:outbox:prune` command — but the F7 rails
 * were absent from the platform's UNIFIED retention seam
 * ({@see RetentionPrunerInterface} → the `coolms:retention:prune` command + the
 * `retention.prune` scheduled handler). That seam exists precisely so retention
 * runs in ONE place instead of each module's prune command being cron-wired
 * independently (they were shipped but nothing ran them); a deploy that only
 * cron-wires the aggregate sweep — the documented one-stop entry point — therefore
 * left this table growing. This pruner plugs it in; the standalone command stays
 * as a parallel entry point (per the interface docblock), so both prune the SAME
 * table at the SAME window (this reuses the maintenance service's very param).
 *
 * Aged by the published cutoff (`now − published_retention_days`, default 7 —
 * published rows are done, so a short window is safe). A non-positive window
 * DISABLES the sweep (the misconfig-can't-wipe-live-data guard), matching
 * the scheduler module's run-retention pruner. Reuses
 * `%coolms_core.outbox.published_retention_days%` so the two entry points can
 * never disagree on the window. Auto-tagged `coolms.retention.pruner` via Core
 * autoconfiguration — zero DI wiring.
 */
final readonly class OutboxRecordRetentionPruner implements RetentionPrunerInterface
{
    public function __construct(
        private OutboxRelayRepositoryInterface $outbox,
        private ClockInterface $clock,
        #[Autowire('%coolms_core.outbox.published_retention_days%')]
        private int $retentionDays,
    ) {
    }

    public function retentionKey(): string
    {
        return 'core.outbox';
    }

    public function retentionLabel(): string
    {
        return 'Delivered outbox rows';
    }

    public function pruneExpired(): int
    {
        if ($this->retentionDays < 1) {
            return 0;
        }

        return $this->outbox->deletePublishedOlderThan($this->cutoff());
    }

    public function countExpired(): int
    {
        if ($this->retentionDays < 1) {
            return 0;
        }

        return $this->outbox->countPublishedOlderThan($this->cutoff());
    }

    private function cutoff(): DateTimeImmutable
    {
        return $this->clock->now()->sub(new DateInterval(sprintf('P%dD', $this->retentionDays)));
    }
}
