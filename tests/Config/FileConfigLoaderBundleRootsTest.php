<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Config;

use CoolMS\CoreModule\Config\FileConfigLoader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A package may ship module YAML, and the application still wins.
 *
 * Both halves are asserted because either alone is satisfiable by a broken
 * loader: one that ignores packages passes the precedence test, and one that
 * ignores the application passes the discovery test.
 */
final class FileConfigLoaderBundleRootsTest extends TestCase
{
    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $dir) {
            $this->rmrf($dir);
        }
        $this->temp = [];
    }

    public function testAPackageShippedConfigIsFound(): void
    {
        $app = $this->makeConfigDir([]);
        $pkg = $this->makeConfigDir([
            'widgets/kinds/banner.yaml' => "type: widget\nid: banner\nlabel: from-package\n",
        ]);

        $loader = new FileConfigLoader($app, new NullLogger(), [$pkg]);

        $loaded = $loader->load('widget', 'banner');
        self::assertIsArray($loaded, 'a package-shipped config must be reachable');
        self::assertSame('from-package', $loaded['label']);
    }

    public function testTheApplicationWinsOverAPackage(): void
    {
        $app = $this->makeConfigDir([
            'widgets/kinds/banner.yaml' => "type: widget\nid: banner\nlabel: from-app\n",
        ]);
        $pkg = $this->makeConfigDir([
            'widgets/kinds/banner.yaml' => "type: widget\nid: banner\nlabel: from-package\n",
        ]);

        $loader = new FileConfigLoader($app, new NullLogger(), [$pkg]);

        $loaded = $loader->load('widget', 'banner');
        self::assertIsArray($loaded);
        self::assertSame(
            'from-app',
            $loaded['label'],
            'an installation must be able to override what a package ships',
        );
    }

    public function testLocateNeverPointsInsideAPackage(): void
    {
        $app = $this->makeConfigDir([]);
        $pkg = $this->makeConfigDir([
            'widgets/kinds/banner.yaml' => "type: widget\nid: banner\nlabel: from-package\n",
        ]);

        $loader = new FileConfigLoader($app, new NullLogger(), [$pkg]);

        self::assertIsArray($loader->load('widget', 'banner'), 'precondition: it is loadable');
        self::assertNull(
            $loader->locate('widget', 'banner'),
            'locate() answers "where does an edit go", and an edit must never be '
            . 'written into a package: composer would discard it on the next update.',
        );
    }

    /** @param array<string, string> $files path under modules/ => contents */
    private function makeConfigDir(array $files): string
    {
        $root = sys_get_temp_dir() . '/coolms-cfg-' . bin2hex(random_bytes(6));
        $this->temp[] = $root;

        foreach ($files as $rel => $body) {
            $full = $root . '/modules/' . $rel;
            $dir = dirname($full);
            if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
                self::fail('could not create ' . $dir);
            }
            file_put_contents($full, $body);
        }

        if (!is_dir($root) && !mkdir($root, 0o777, true) && !is_dir($root)) {
            self::fail('could not create ' . $root);
        }

        return $root;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }

        rmdir($dir);
    }
}
