<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

/**
 * Loads raw config arrays by type and id from the filesystem.
 *
 * Scans config/modules/{module}/{type-dir}/*.yaml, matches by 'id' (or 'tree'
 * for navigraph) field inside the YAML, then merges a theme override on top
 * when a themeSlug is given.
 *
 * Tag: coolms.config_loader
 */
interface ConfigLoaderInterface
{
    /**
     * @return array<string, mixed>|null raw config array, or null when not found
     */
    public function load(string $type, string $id, ?string $themeSlug = null): ?array;
}
