<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupContributorInterface;
use CoolMS\Core\Backup\BackupException;

use function array_fill_keys;
use function implode;
use function in_array;
use function sprintf;

/**
 * Topologically orders contributor keys on `restoreAfter()` — every key's declared
 * dependencies (that are also in the set) precede it.
 *
 * Extracted from {@see BackupRunner} because it now has TWO callers with the same
 * question and different inputs: the runner orders the keys present in ONE bundle,
 * while {@see BackupTableRegistry} orders every registered contributor to derive a
 * platform-wide FK-safe table order for the sync applier. Dependencies outside the
 * given set are ignored (a bundle may legitimately omit a module the target doesn't
 * run) — which is exactly why the set is a parameter rather than read from the tag.
 *
 * Stateless and dependency-free: constructed with `new`, not wired.
 */
final class ContributorRestoreOrder
{
    /**
     * @param list<string>                              $keys
     * @param array<string, BackupContributorInterface> $registered
     *
     * @throws BackupException on a dependency cycle
     *
     * @return list<string>
     */
    public function order(array $keys, array $registered): array
    {
        $ordered = [];
        $placed = [];
        $inSet = array_fill_keys($keys, true);

        $place = function (string $key, array $trail) use (&$place, &$ordered, &$placed, $inSet, $registered): void {
            if (isset($placed[$key])) {
                return;
            }
            if (in_array($key, $trail, true)) {
                throw new BackupException(sprintf('Backup restore dependency cycle: %s -> %s.', implode(' -> ', $trail), $key));
            }
            foreach ($registered[$key]->restoreAfter() as $dep) {
                if (isset($inSet[$dep])) {
                    $place($dep, [...$trail, $key]);
                }
            }
            $placed[$key] = true;
            $ordered[] = $key;
        };

        foreach ($keys as $key) {
            $place($key, []);
        }

        return $ordered;
    }
}
