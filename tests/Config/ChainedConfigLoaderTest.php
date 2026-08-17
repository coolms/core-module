<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Config;

use CoolMS\Core\Config\ConfigOverride;
use CoolMS\Core\Config\ConfigOverrideRepositoryInterface;
use CoolMS\CoreModule\Config\ChainedConfigLoader;
use CoolMS\CoreModule\Config\FileConfigLoader;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function sys_get_temp_dir;
use function uniqid;

/**
 * Reading config with the database layered over the files.
 *
 * Without this the write path is a no-op nobody notices until production: an
 * edit saved to the database on a read-only host would never be read back, and
 * the screen would go on showing the file it was meant to replace.
 */
#[CoversClass(ChainedConfigLoader::class)]
final class ChainedConfigLoaderTest extends TestCase
{
    #[Test]
    public function aStoredOverrideWins(): void
    {
        $loader = $this->loader(new ConfigOverride('dashboard', 'main', ['type' => 'dashboard', 'widgets' => ['saved']]));

        self::assertSame(['type' => 'dashboard', 'widgets' => ['saved']], $loader->load('dashboard', 'main'));
    }

    /**
     * The normal case, and the one that matters for every existing consumer:
     * until something writes an override, this behaves exactly like the file
     * loader it replaced as `ConfigLoaderInterface`.
     */
    #[Test]
    public function withNoOverrideItFallsThroughToTheFiles(): void
    {
        // An empty temp dir, so the file loader genuinely finds nothing rather
        // than being stubbed into saying so.
        self::assertNull($this->loader(null)->load('dashboard', 'main'));
    }

    /**
     * Reading must never write, however the store behaves. Cheap to assert and
     * worth having: a loader that upserted a "default" row on first read would
     * quietly turn a shipped file into a database row on every fresh install,
     * and nothing about the screen would look different.
     *
     * ⚠️ The unmigrated-database case is NOT tested here any more — it moved
     * with the catch into `ConfigOverrideRepository`, which is the only layer
     * allowed to name an ORM exception. From here that case is simply
     * "the port returned null", covered by the test above.
     */
    #[Test]
    public function readingNeverWrites(): void
    {
        $repository = new class implements ConfigOverrideRepositoryInterface {
            public function findOverride(string $type, string $id): ?ConfigOverride
            {
                return null;
            }

            public function upsert(string $type, string $id, array $data): ConfigOverride
            {
                throw new LogicException('The loader must never write.');
            }

            public function deleteOverride(string $type, string $id): bool
            {
                throw new LogicException('The loader must never write.');
            }
        };

        self::assertNull(new ChainedConfigLoader($this->files(), $repository)->load('dashboard', 'main'));
    }

    private function loader(?ConfigOverride $override): ChainedConfigLoader
    {
        return new ChainedConfigLoader($this->files(), $this->repository($override));
    }

    private function files(): FileConfigLoader
    {
        return new FileConfigLoader(sys_get_temp_dir() . '/coolms-config-' . uniqid(), new NullLogger());
    }

    private function repository(?ConfigOverride $override): ConfigOverrideRepositoryInterface
    {
        return new class($override) implements ConfigOverrideRepositoryInterface {
            public function __construct(private readonly ?ConfigOverride $override)
            {
            }

            public function findOverride(string $type, string $id): ?ConfigOverride
            {
                return $this->override;
            }

            public function upsert(string $type, string $id, array $data): ConfigOverride
            {
                return new ConfigOverride($type, $id, $data);
            }

            public function deleteOverride(string $type, string $id): bool
            {
                return false;
            }
        };
    }
}
