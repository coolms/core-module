<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Space;

use CoolMS\Core\Identity\UserInterface;
use CoolMS\Core\Space\SpaceInterface;
use CoolMS\Core\Space\SpaceProviderInterface;

/**
 * Aggregates every tagged {@see SpaceProviderInterface} into one
 * sorted, de-duplicated list. Order: ascending `priority`, then `key`.
 *
 * Each Library module subclasses this and binds the iterable to its own
 * tag (e.g. `coolms.media.space_provider`, `coolms.document.space_provider`)
 * via `#[AutowireIterator]`. Keeping the registry generic + the subclass
 * trivial preserves the FE-facing seam (per-module service IDs) while
 * eliminating the aggregation duplication.
 */
abstract readonly class SpaceRegistry
{
    /**
     * @param iterable<SpaceProviderInterface> $providers
     */
    public function __construct(
        protected iterable $providers,
    ) {
    }

    /**
     * @return list<SpaceInterface>
     */
    public function spacesFor(UserInterface $user): array
    {
        $spaces = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->spacesFor($user) as $space) {
                $spaces[$space->key] = $space; // de-dupe on key
            }
        }
        $list = array_values($spaces);
        usort(
            $list,
            static fn (SpaceInterface $a, SpaceInterface $b): int => $a->priority <=> $b->priority ?: strcmp($a->key, $b->key),
        );

        return $list;
    }
}
