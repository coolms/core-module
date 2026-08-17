<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function file_exists;
use function is_writable;
use function preg_match;
use function sprintf;

/**
 * Config data as YAML under `config/modules`.
 *
 * The half of the store that a developer can read in a diff and commit — which
 * is the whole reason it is tried first. Config data is SHARED: an arrangement
 * saved in dev is meant to travel to the rest of the team through git, and a
 * row in a database does not.
 */
final readonly class FileConfigWriter implements ConfigWriterInterface
{
    /**
     * Where a config with no existing file goes.
     *
     * `generated` is an existing `config/modules` sibling — the Form module
     * already writes its admin-authored forms there — so a machine-written file
     * is visibly separate from the hand-written ones without inventing a
     * convention. A config that already HAS a file is rewritten in place, so
     * this only ever applies to something brand new.
     */
    public const string GENERATED_MODULE = 'generated';

    public function __construct(
        private FileConfigLoader $files,
        #[Autowire('%kernel.project_dir%/config')]
        private string $configDir,
    ) {
    }

    /**
     * Can this deployment write this config as a file?
     *
     * An existing file must itself be writable; a new one needs a writable
     * directory — and since the directory may not exist yet, the nearest
     * ancestor that DOES is the one that decides, because that is the one
     * `mkdir` would have to create into.
     */
    public function canWrite(string $type, string $id): bool
    {
        if (!$this->isSafe($type) || !$this->isSafe($id)) {
            return false;
        }

        $existing = $this->files->locate($type, $id);
        if (null !== $existing) {
            return is_writable($existing);
        }

        $dir = dirname($this->newPath($type, $id));
        while ('' !== $dir && '/' !== $dir && !file_exists($dir)) {
            $dir = dirname($dir);
        }

        return '' !== $dir && is_writable($dir);
    }

    public function write(string $type, string $id, array $data): string
    {
        // Belt as well as braces: `canWrite()` already refuses these, but a
        // direct call must not be the difference between a config file and a
        // path traversal.
        $this->assertSafe($type);
        $this->assertSafe($id);

        $path = $this->files->locate($type, $id) ?? $this->newPath($type, $id);
        $dir = dirname($path);

        if (!file_exists($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create config directory "%s".', $dir));
        }

        // `type` and `id` are stamped rather than trusted from $data. They are
        // how the loader finds this file again, so a caller that forgot them —
        // or, worse, passed a different id — would write a file that loads as
        // something else or not at all.
        $full = ['type' => $type, 'id' => $id] + $data;

        if (false === file_put_contents($path, Yaml::dump($full, 6, 4))) {
            throw new RuntimeException(sprintf('Cannot write config file "%s".', $path));
        }

        return $path;
    }

    public function delete(string $type, string $id): bool
    {
        $path = $this->files->locate($type, $id);

        return null !== $path && is_writable($path) && unlink($path);
    }

    /** `config/modules/generated/{type}/{id}.yaml` — where the loader's glob will find it. */
    private function newPath(string $type, string $id): string
    {
        return $this->configDir . '/modules/' . self::GENERATED_MODULE . '/' . $type . '/' . $id . '.yaml';
    }

    private function isSafe(string $key): bool
    {
        return 1 === preg_match(self::KEY_PATTERN, $key);
    }

    private function assertSafe(string $key): void
    {
        if (!$this->isSafe($key)) {
            throw new InvalidArgumentException(sprintf('Config key "%s" is not a safe path segment; it must match %s.', $key, self::KEY_PATTERN));
        }
    }
}
