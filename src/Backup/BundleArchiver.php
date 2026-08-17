<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

use function is_dir;
use function is_string;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Packs a backup bundle directory (`manifest.json` + `data/<key>/<table>.json` +
 * sharded blobs) into a SINGLE `.zip` and back — the on-the-wire serialization
 * for controller→edge sync: a bundle is a directory tree, but a
 * pull is one HTTP body, so it must round-trip through one artifact.
 *
 * {@see extract()} is **zip-slip-safe**: every entry name is validated before
 * `extractTo()`, so a malicious archive cannot write outside the destination
 * (`../`, absolute paths, backslashes, NUL). Bundle entries are all plain
 * relative paths, so any entry that isn't is rejected outright.
 */
final class BundleArchiver
{
    /**
     * Zip every file under `$bundleDir` into `$zipPath` (paths relative to the
     * bundle root, forward-slashed).
     */
    public function archive(string $bundleDir, string $zipPath): void
    {
        if (!is_dir($bundleDir)) {
            throw new BackupException(sprintf('Cannot archive "%s": not a directory.', $bundleDir));
        }

        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new BackupException(sprintf('Could not create archive at "%s".', $zipPath));
        }

        $base = rtrim(str_replace('\\', '/', $bundleDir), '/') . '/';
        $baseLen = strlen($base);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bundleDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $local = substr(str_replace('\\', '/', $file->getPathname()), $baseLen);
            $zip->addFile($file->getPathname(), $local);
        }

        $zip->close();
    }

    /**
     * Extract `$zipPath` into `$destDir`, refusing any entry that could escape it.
     */
    public function extract(string $zipPath, string $destDir): void
    {
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new BackupException(sprintf('Could not open archive at "%s".', $zipPath));
        }

        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || $this->isUnsafeEntry($name)) {
                $zip->close();

                throw new BackupException(sprintf('Refusing to extract archive: unsafe entry "%s".', is_string($name) ? $name : '?'));
            }
        }

        if (!$zip->extractTo($destDir)) {
            $zip->close();

            throw new BackupException(sprintf('Could not extract archive into "%s".', $destDir));
        }
        $zip->close();
    }

    /** True for any entry that could write outside the destination (zip-slip). */
    private function isUnsafeEntry(string $name): bool
    {
        return '' === $name
            || str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || str_contains($name, '..')
            || str_contains($name, "\0")
            || str_contains($name, ':'); // drive-letter / stream on Windows
    }
}
