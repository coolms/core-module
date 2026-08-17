<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Retention;

use CoolMS\Core\Retention\RetentionPrunerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Runs every registered {@see RetentionPrunerInterface} in one pass — the single
 * seam behind the `coolms:retention:prune` command AND the `retention.prune`
 * scheduled handler, so all module retention actually runs (and can be
 * cron-scheduled once, not per-module).
 *
 * The `#[AutowireIterator]` pin is deliberate (per the tagged-iterator glob
 * footgun): binding the collection through the Core Extension's `setArgument`
 * would be clobbered by the `App\:` services glob re-registering this class, so
 * the tag is consumed on the constructor param directly.
 */
final readonly class RetentionPruneRunner
{
    /**
     * @param iterable<RetentionPrunerInterface> $pruners
     */
    public function __construct(
        #[AutowireIterator('coolms.retention.pruner')]
        private iterable $pruners,
    ) {
    }

    /**
     * Prune every registered store; returns the per-pruner rows removed.
     *
     * @return list<array{key: string, label: string, removed: int}>
     */
    public function prune(): array
    {
        $results = [];
        foreach ($this->pruners as $pruner) {
            $results[] = [
                'key' => $pruner->retentionKey(),
                'label' => $pruner->retentionLabel(),
                'removed' => $pruner->pruneExpired(),
            ];
        }

        return $results;
    }

    /**
     * How many rows each registered pruner WOULD remove, without deleting
     * anything — the dry-run preview.
     *
     * @return list<array{key: string, label: string, prunable: int}>
     */
    public function preview(): array
    {
        $results = [];
        foreach ($this->pruners as $pruner) {
            $results[] = [
                'key' => $pruner->retentionKey(),
                'label' => $pruner->retentionLabel(),
                'prunable' => $pruner->countExpired(),
            ];
        }

        return $results;
    }
}
