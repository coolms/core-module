<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

use CoolMS\Core\Config\ConfigOverrideRepositoryInterface;

/**
 * Reads config data with the database layered over the files.
 *
 * The read half of the store, and it exists for one reason: a write that landed
 * in the database because `config/` was read-only has to come back on the next
 * request. Without this the whole write path is a no-op nobody notices until
 * production.
 *
 * ## The database WINS
 *
 * Same precedence the Form module's boot overlay has, and for the same reason:
 * the row is the more recent decision. A file that disagrees is what the
 * deployment shipped with, not what its operator last chose.
 *
 * ⚠️ A stored override REPLACES the file rather than merging into it — a
 * half-file, half-row config would be a shape neither of them can be read as.
 * Theme overrides are skipped along with it; they layer over a file's answer,
 * and a saved override IS the answer.
 *
 * ## No error handling here, on purpose
 *
 * An unmigrated database — a fresh checkout, a CI job that builds before it
 * migrates — has to degrade to file-only config rather than take the request
 * down. That belongs to the repository, which is the only layer allowed to name
 * an ORM exception (the platform's ORM-agnostic rule, enforced by a phpstan
 * boundary check). From here a store that cannot answer and a config nobody has
 * overridden are the same thing: null, then the files.
 *
 * ⚠️ Read the persistence adapter's `ConfigOverrideRepository::findOverride()`
 * before widening what it swallows. Catching one exception too many there makes
 * a broken table look exactly like an empty one, and this loader would report
 * nothing wrong while silently ignoring every saved override.
 */
final readonly class ChainedConfigLoader implements ConfigLoaderInterface
{
    public function __construct(
        private FileConfigLoader $files,
        private ConfigOverrideRepositoryInterface $overrides,
    ) {
    }

    public function load(string $type, string $id, ?string $themeSlug = null): ?array
    {
        $override = $this->overrides->findOverride($type, $id);

        return null !== $override ? $override->data : $this->files->load($type, $id, $themeSlug);
    }
}
