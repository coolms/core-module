<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ChangeFeed;

use CoolMS\Core\ChangeFeed\SyncBlobContributorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function array_slice;
use function array_values;
use function count;

/**
 * Fans the side-channel blob operations out over every registered
 * {@see SyncBlobContributorInterface}, so callers — the controller's blob endpoint and
 * the edge's pull loop, both in the sync module -- never name a specific module.
 *
 * The `#[AutowireIterator]` pin is deliberate: the same tagged-iterator glob footgun
 * {@see \CoolMS\CoreModule\Backup\BackupRunner} documents (a Core-Extension
 * `setArgument` would be clobbered by the `App\:` services glob re-registering this class).
 */
#[Autoconfigure(public: true)] // consumed from the sync module; survive container pruning
final class SyncBlobRegistry
{
    /**
     * @param iterable<SyncBlobContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator('coolms.sync.blob.contributor')]
        private readonly iterable $contributors,
    ) {
    }

    /**
     * The bytes for `$hash` from whichever contributor holds them, or null if none does.
     *
     * First non-null wins: a hash is content-addressed, so if two contributors somehow
     * held the same bytes they would be the SAME bytes — the choice cannot be wrong.
     */
    public function read(string $hash): ?string
    {
        foreach ($this->contributors as $contributor) {
            $bytes = $contributor->readBlob($hash);
            if (null !== $bytes) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * Every contributor's missing hashes, capped at `$limit` IN TOTAL.
     *
     * The cap is global rather than per-contributor so one pull's blob work stays bounded
     * no matter how many modules join. The channel is convergent — whatever is dropped
     * here is simply refetched next pull — so a cap can delay bytes but never lose them.
     *
     * @return list<string>
     */
    public function pending(int $limit): array
    {
        $hashes = [];
        foreach ($this->contributors as $contributor) {
            if (count($hashes) >= $limit) {
                break;
            }
            foreach ($contributor->pendingBlobHashes($limit - count($hashes)) as $hash) {
                $hashes[$hash] = $hash; // de-duplicate across contributors
            }
        }

        return array_slice(array_values($hashes), 0, $limit);
    }

    /**
     * Hand fetched bytes to every contributor; returns true if any accepted them.
     *
     * Not first-wins: one hash can matter to several contributors (and, within VFS, to
     * several nodes), and each is responsible for its own placement. Each verifies the
     * bytes against the hash itself and throws on mismatch — see the interface.
     */
    public function store(string $hash, string $bytes): bool
    {
        $accepted = false;
        foreach ($this->contributors as $contributor) {
            $accepted = $contributor->storeBlob($hash, $bytes) || $accepted;
        }

        return $accepted;
    }
}
