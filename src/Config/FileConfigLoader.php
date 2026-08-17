<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Filesystem implementation of ConfigLoaderInterface.
 *
 * Resolution order:
 *   1. Scan config/modules/* (two levels) for YAML matching type+id fields
 *   2. Merge config/themes/{themeSlug} override on top when themeSlug given
 *
 * Returns null when no matching config found.
 */
final readonly class FileConfigLoader implements ConfigLoaderInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config')]
        private string $configDir,
        private LoggerInterface $logger,
    ) {
    }

    public function load(string $type, string $id, ?string $themeSlug = null): ?array
    {
        $base = $this->scanForConfig($this->configDir . '/modules', $type, $id);

        if (null !== $themeSlug) {
            $override = $this->scanForConfig($this->configDir . '/themes/' . $themeSlug, $type, $id);
            if (null !== $override) {
                $base = null !== $base ? array_merge_recursive($base, $override) : $override;
            }
        }

        return $base;
    }

    /**
     * The PATH of the file this type+id would be read from, or null.
     *
     * Exists so a writer can put an edit back where the reader will find it. It
     * is the same scan as {@see self::scanForConfig()} stopping one step
     * earlier, and it lives here rather than in the writer for one reason: two
     * copies of "which file is this config" drift, and the day they disagree an
     * admin's edit lands in a file nothing loads. Only `config/modules` is
     * searched — a theme override is a layer on top, never the thing you edit.
     */
    public function locate(string $type, string $id): ?string
    {
        foreach ($this->scan($this->configDir . '/modules') as $file => $data) {
            if ($this->matches($data, $type, $id)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Scan all YAML files two levels below the given root (root/{module}/{type-dir}/{file}.yaml).
     * Match the first file whose 'type' equals $type AND whose 'id' (or 'tree') equals $id.
     *
     * @return array<string, mixed>|null
     */
    private function scanForConfig(string $root, string $type, string $id): ?array
    {
        foreach ($this->scan($root) as $data) {
            if ($this->matches($data, $type, $id)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Every parseable YAML two levels below $root, keyed by absolute path.
     *
     * @return iterable<string, array<mixed>>
     */
    private function scan(string $root): iterable
    {
        foreach (glob($root . '/*/*/*.yaml') ?: [] as $file) {
            try {
                $data = Yaml::parseFile($file);
            } catch (ParseException $e) {
                $this->logger->warning('ConfigLoader: YAML parse error in {file}: {error}', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (is_array($data)) {
                yield $file => $data;
            }
        }
    }

    /** @param array<mixed> $data */
    private function matches(array $data, string $type, string $id): bool
    {
        if (($data['type'] ?? null) !== $type) {
            return false;
        }

        // navigraph type uses 'tree' as identifier; everything else uses 'id'
        return ($data['id'] ?? $data['tree'] ?? null) === $id;
    }
}
