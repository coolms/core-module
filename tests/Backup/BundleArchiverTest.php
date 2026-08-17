<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Backup;

use CoolMS\Core\Backup\BackupException;
use CoolMS\CoreModule\Backup\BundleArchiver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * @covers \CoolMS\CoreModule\Backup\BundleArchiver
 */
final class BundleArchiverTest extends TestCase
{
    private string $root;
    private Filesystem $fs;

    #[Test]
    public function archivesAndExtractsABundleTreeIntact(): void
    {
        $bundle = $this->root . '/bundle';
        $this->fs->mkdir($bundle . '/data/identity');
        $this->fs->mkdir($bundle . '/data/vfs/blobs/aa');
        file_put_contents($bundle . '/manifest.json', '{"formatVersion":1}');
        file_put_contents($bundle . '/data/identity/groups.json', '["g1","g2"]');
        file_put_contents($bundle . '/data/vfs/blobs/aa/deadbeef', "\x00\x01binary\xff");

        $archiver = new BundleArchiver();
        $zip = $this->root . '/snapshot.zip';
        $archiver->archive($bundle, $zip);
        self::assertFileExists($zip);

        $out = $this->root . '/restored';
        $archiver->extract($zip, $out);

        self::assertSame('{"formatVersion":1}', file_get_contents($out . '/manifest.json'));
        self::assertSame('["g1","g2"]', file_get_contents($out . '/data/identity/groups.json'));
        self::assertSame("\x00\x01binary\xff", file_get_contents($out . '/data/vfs/blobs/aa/deadbeef'), 'blob bytes round-trip verbatim');
    }

    #[Test]
    public function refusesToExtractAZipSlipEntry(): void
    {
        $zip = $this->root . '/evil.zip';
        $archive = new ZipArchive();
        self::assertTrue(true === $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('manifest.json', '{}');
        $archive->addFromString('../escape.txt', 'pwned'); // traversal outside the destination
        $archive->close();

        $out = $this->root . '/dest';

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('unsafe entry');

        try {
            new BundleArchiver()->extract($zip, $out);
        } finally {
            // The traversal target must never have been written.
            self::assertFileDoesNotExist($this->root . '/escape.txt');
        }
    }

    #[Test]
    public function refusesToExtractAnAbsolutePathEntry(): void
    {
        $zip = $this->root . '/abs.zip';
        $archive = new ZipArchive();
        self::assertTrue(true === $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $archive->addFromString('/etc/pwned', 'pwned');
        $archive->close();

        $this->expectException(BackupException::class);

        new BundleArchiver()->extract($zip, $this->root . '/dest2');
    }

    #[Test]
    public function archivingANonDirectoryThrows(): void
    {
        $this->expectException(BackupException::class);

        new BundleArchiver()->archive($this->root . '/does-not-exist', $this->root . '/x.zip');
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->root = sys_get_temp_dir() . '/coolms-archiver-test-' . bin2hex(random_bytes(6));
        $this->fs->mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->root);
    }
}
