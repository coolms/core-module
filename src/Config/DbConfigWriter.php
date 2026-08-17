<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

use CoolMS\Core\Config\ConfigOverrideRepositoryInterface;

use function preg_match;

/**
 * Config data as a row, for hosts where `config/` cannot be written.
 *
 * The fallback, not the preference: a row is invisible to `git diff` and does
 * not travel to a colleague's checkout, so it is what a read-only deployment
 * gets rather than what a developer gets. See {@see ChainedConfigWriter} for
 * who decides.
 */
final readonly class DbConfigWriter implements ConfigWriterInterface
{
    public function __construct(
        private ConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    /**
     * Always, for any key the platform considers valid — which is what makes
     * this the LAST link in the chain rather than a peer of the file store. A
     * database that is unreachable is an outage, not a reason to quietly put an
     * operator's config somewhere else.
     *
     * ⚠️ The key check is NOT redundant with the file store's, even though a
     * row cannot be a path traversal. If this accepted keys the file store
     * refuses, an unsafe id would simply FALL THROUGH to the database and be
     * stored — the attack surface closed in one place and left open one link
     * down the chain. Refusing in both makes the chain say "no store can hold
     * this", which is the truth.
     */
    public function canWrite(string $type, string $id): bool
    {
        return 1 === preg_match(self::KEY_PATTERN, $type) && 1 === preg_match(self::KEY_PATTERN, $id);
    }

    public function write(string $type, string $id, array $data): string
    {
        // Stamped for the same reason the file writer stamps them: a row read
        // back has to be indistinguishable from the file it stands in for, or
        // every consumer needs a second code path for the DB case.
        $this->overrides->upsert($type, $id, ['type' => $type, 'id' => $id] + $data);

        return $this->describe($type, $id);
    }

    public function delete(string $type, string $id): bool
    {
        return $this->overrides->deleteOverride($type, $id);
    }

    /** Deliberately not a path: a caller that treats this as one should break loudly. */
    public function describe(string $type, string $id): string
    {
        return 'db://' . $type . '/' . $id;
    }
}
