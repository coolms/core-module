<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Config;

use CoolMS\CoreModule\Config\FileConfigLoader;
use CoolMS\CoreModule\Config\FileConfigWriter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Writing config data back as YAML.
 *
 * Against a real temporary `config/` rather than a mocked filesystem, because
 * every claim worth making here is about the filesystem: that an edit lands in
 * the file the loader will read next, and that a round trip survives.
 */
#[CoversClass(FileConfigWriter::class)]
final class FileConfigWriterTest extends TestCase
{
    private string $configDir;
    private Filesystem $fs;

    /**
     * THE test: an edit must land in the file the loader already reads, not
     * beside it. A second file with the same type+id would leave the loader
     * picking whichever the glob happened to reach first.
     */
    #[Test]
    public function anExistingConfigIsRewrittenInPlace(): void
    {
        $existing = $this->configDir . '/modules/core/dashboard/dashboard.yaml';
        $this->fs->dumpFile($existing, Yaml::dump(['type' => 'dashboard', 'id' => 'main', 'widgets' => ['before']]));

        $where = $this->writer()->write('dashboard', 'main', ['widgets' => ['after']]);

        self::assertSame($existing, $where);
        self::assertSame(['after'], Yaml::parseFile($existing)['widgets']);
    }

    #[Test]
    public function aBrandNewConfigGoesToTheGeneratedModule(): void
    {
        $where = $this->writer()->write('dashboard', 'sales', ['widgets' => []]);

        self::assertSame($this->configDir . '/modules/generated/dashboard/sales.yaml', $where);
        // …and the directory it needed did not exist a moment ago.
        self::assertFileExists($where);
    }

    /**
     * The written file must be loadable BY TYPE AND ID, which is not the same
     * claim as "a file was written": the loader matches on the `type:` and `id:`
     * keys inside it, so a caller that omitted them — or passed a different id
     * in the payload — would produce a file nothing ever reads.
     */
    #[Test]
    public function whatIsWrittenIsWhatTheLoaderFinds(): void
    {
        $writer = $this->writer();
        // Note: no 'type'/'id' in the payload, and a contradictory id inside it.
        $writer->write('dashboard', 'main', ['id' => 'wrong', 'widgets' => [['widget' => 'vfs.file-count']]]);

        $loaded = $this->loader()->load('dashboard', 'main');

        self::assertNotNull($loaded);
        self::assertSame('main', $loaded['id']);
        self::assertSame('dashboard', $loaded['type']);
        self::assertSame([['widget' => 'vfs.file-count']], $loaded['widgets']);
    }

    #[Test]
    public function aWritableConfigDirCanTakeBothNewAndExistingConfigs(): void
    {
        $writer = $this->writer();

        self::assertTrue($writer->canWrite('dashboard', 'never-seen'));

        $this->fs->dumpFile(
            $this->configDir . '/modules/core/dashboard/dashboard.yaml',
            Yaml::dump(['type' => 'dashboard', 'id' => 'main']),
        );
        self::assertTrue($writer->canWrite('dashboard', 'main'));
    }

    #[Test]
    public function deletingRemovesTheFileTheLoaderWouldHaveRead(): void
    {
        $writer = $this->writer();
        $path = $writer->write('dashboard', 'main', ['widgets' => []]);

        self::assertTrue($writer->delete('dashboard', 'main'));
        self::assertFileDoesNotExist($path);
        self::assertNull($this->loader()->load('dashboard', 'main'));
    }

    #[Test]
    public function deletingSomethingWithNoFileSaysSo(): void
    {
        self::assertFalse($this->writer()->delete('dashboard', 'never-existed'));
    }

    /**
     * ⚠️ A config key becomes a PATH SEGMENT. Harmless while every
     * caller passed a constant; a traversal the moment one is a route
     * parameter, which is what section dashboards made it.
     *
     * Refused by the STORE, not by its callers — a store that depends on being
     * called carefully is one bad call away from a hole.
     */
    #[Test]
    #[TestWith(['../../../../etc/passwd'])]
    #[TestWith(['../main'])]
    #[TestWith(['nested/id'])]
    #[TestWith([''])]
    #[TestWith(['.hidden'])]
    public function aKeyThatIsNotASafePathSegmentIsRefused(string $id): void
    {
        $writer = $this->writer();

        self::assertFalse($writer->canWrite('dashboard', $id));

        $this->expectException(InvalidArgumentException::class);
        $writer->write('dashboard', $id, []);
    }

    #[Test]
    public function anUnsafeTYPEIsRefusedToo(): void
    {
        self::assertFalse($this->writer()->canWrite('../dashboard', 'main'));
    }

    protected function setUp(): void
    {
        $this->fs = new Filesystem();
        $this->configDir = sys_get_temp_dir() . '/coolms-cfg-' . uniqid();
        $this->fs->mkdir($this->configDir . '/modules/core/dashboard');
    }

    protected function tearDown(): void
    {
        $this->fs->remove($this->configDir);
    }

    private function writer(): FileConfigWriter
    {
        return new FileConfigWriter($this->loader(), $this->configDir);
    }

    private function loader(): FileConfigLoader
    {
        return new FileConfigLoader($this->configDir, new NullLogger());
    }
}
