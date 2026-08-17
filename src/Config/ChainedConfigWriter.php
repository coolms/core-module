<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

use InvalidArgumentException;
use RuntimeException;

use function preg_match;
use function sprintf;

/**
 * Picks where a config write lands: the first store this host will accept
 *.
 *
 * ## The rule, and why it is shorter than the Form module's
 *
 * File when `config/modules` is writable, database when it is not. That is all.
 *
 * Form's `ChainedConfigWriter` has a third rule — never rewrite a MODULE-SHIPPED
 * config, send those to the database as an override — and it is right for
 * forms, whose sources are shipped inside modules. It does not apply here
 * because of what `config/modules` IS: the platform's own override layer, the
 * place a deployment keeps the data it has decided to change. A file there is
 * not a module's source; it is the previous answer to this same question. So
 * rewriting it in place is correct, and the alternative — a database row
 * shadowing a git-tracked file that still says something else — is exactly the
 * thing to avoid, because the file stays in the diff while nothing it says is
 * true any more.
 *
 * ## One config, ONE copy
 *
 * Which is why a write also DELETES the config from every store that did not
 * take it. A row left over from a read-only spell outranks the file written
 * after it — the reader lets the database win — and the result is a screen no
 * file on disk explains. Two stores mean two chances to leave one behind, and
 * nothing about that failure announces itself.
 *
 * The same reasoning makes deletion clear everything: a "reset to default" that
 * dropped only the file would leave the row quietly winning the next read.
 */
final readonly class ChainedConfigWriter implements ConfigWriterInterface
{
    /**
     * @param list<ConfigWriterInterface> $stores in PREFERENCE order — file
     *                                            before database. Passed as an
     *                                            explicit list rather than a
     *                                            tagged iterator: the order is
     *                                            the policy, and a tag would
     *                                            let an unrelated module change
     *                                            where an operator's config
     *                                            lands by merely existing
     */
    public function __construct(
        private array $stores,
    ) {
    }

    public function canWrite(string $type, string $id): bool
    {
        foreach ($this->stores as $store) {
            if ($store->canWrite($type, $id)) {
                return true;
            }
        }

        return false;
    }

    public function write(string $type, string $id, array $data): string
    {
        foreach ($this->stores as $index => $store) {
            if (!$store->canWrite($type, $id)) {
                continue;
            }

            $where = $store->write($type, $id, $data);

            foreach ($this->stores as $other => $loser) {
                if ($other !== $index) {
                    $loser->delete($type, $id);
                }
            }

            return $where;
        }

        // An unusable KEY is the caller's mistake, not the host's, and the two
        // must not answer alike: a section named `../../etc/passwd` deserves a
        // 422, while "every store refused a perfectly good key" is a 500. Both
        // arrive here as "nobody took it", so the distinction has to be drawn
        // by asking WHY — the pattern is the store's, borrowed rather than
        // restated, so there is still one definition of a valid key.
        if (1 !== preg_match(self::KEY_PATTERN, $type) || 1 !== preg_match(self::KEY_PATTERN, $id)) {
            throw new InvalidArgumentException(sprintf('"%s/%s" is not a valid config key; it must match %s.', $type, $id, self::KEY_PATTERN));
        }

        // Unreachable while the database store is last and accepts every valid
        // key. Kept because "no store took it" must never look like a
        // successful save.
        throw new RuntimeException(sprintf('No config store can hold "%s/%s" on this host.', $type, $id));
    }

    public function delete(string $type, string $id): bool
    {
        $removed = false;
        foreach ($this->stores as $store) {
            // ⚠️ The call goes on the LEFT of the ||. Written the other way it
            // short-circuits after the first success and leaves the other
            // store's copy behind — the precise bug this method exists to stop.
            $removed = $store->delete($type, $id) || $removed;
        }

        return $removed;
    }
}
