<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Dashboard;

use CoolMS\CoreModule\Config\ConfigLoaderInterface;
use CoolMS\CoreModule\Dashboard\DashboardLayoutProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function array_map;
use function str_replace;

/**
 * Turning a hand-edited config file into a layout.
 *
 * Every test past the first is about the same thing: this input is UNTRUSTED,
 * because a person types it. A bad line loses its own card and nothing else,
 * and says so in the log — a dashboard that 500s over one typo in a config file
 * would be a worse answer than the typo.
 */
#[CoversClass(DashboardLayoutProvider::class)]
final class DashboardLayoutProviderTest extends TestCase
{
    #[Test]
    public function aLayoutIsReadInFileOrder(): void
    {
        $layout = $this->provider([
            'widgets' => [
                ['widget' => 'vfs.storage-used', 'columns' => 6],
                ['widget' => 'vfs.file-count'],
                ['widget' => 'other.thing', 'hidden' => true],
            ],
        ])->load();

        self::assertSame(
            ['vfs.storage-used', 'vfs.file-count', 'other.thing'],
            array_map(static fn ($p): string => $p->widget, $layout->placements),
        );
        self::assertSame(6, $layout->placements[0]->columns);
        // Omitted rather than defaulted: null is "keep the module's width",
        // which is a different instruction from "make it four".
        self::assertNull($layout->placements[1]->columns);
        self::assertTrue($layout->placements[2]->hidden);
    }

    /**
     * No file is the NORMAL state, not a failure — it is what every install has
     * until someone arranges something, and it must mean "the catalogue's own
     * order stands".
     */
    #[Test]
    public function noFileMeansNoLayout(): void
    {
        self::assertTrue($this->provider(null)->load()->isEmpty());
    }

    #[Test]
    public function aFileWithNoWidgetsKeyMeansNoLayout(): void
    {
        self::assertTrue($this->provider(['type' => 'dashboard', 'id' => 'main'])->load()->isEmpty());
    }

    #[Test]
    public function theDashboardIdIsWhatIsAskedFor(): void
    {
        $loader = new class implements ConfigLoaderInterface {
            /** @var list<array{string, string, string|null}> */
            public array $asked = [];

            public function load(string $type, string $id, ?string $themeSlug = null): ?array
            {
                $this->asked[] = [$type, $id, $themeSlug];

                return null;
            }
        };

        $provider = new DashboardLayoutProvider($loader, new NullTestLogger());
        $provider->load();
        $provider->load('sales');

        // ⚠️ The third element must stay null. The config loader merges a theme
        // override with array_merge_recursive, which CONCATENATES lists — asking
        // for one would append a second copy of every placement.
        self::assertSame([['dashboard', 'main', null], ['dashboard', 'sales', null]], $loader->asked);
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[TestWith([[], 'no "widget" id'])]
    #[TestWith([['widget' => ''], 'no "widget" id'])]
    #[TestWith([['widget' => 'a', 'columns' => 'six'], 'whole number'])]
    #[TestWith([['widget' => 'a', 'columns' => 13], 'the grid has 12'])]
    #[TestWith([['widget' => 'a', 'hidden' => 'yes'], 'true or false'])]
    public function aBadEntryIsDroppedAndLoggedRatherThanThrown(array $entry, string $expectedInLog): void
    {
        $logger = new NullTestLogger();
        $layout = $this->provider(['widgets' => [$entry, ['widget' => 'survivor']]], $logger)->load();

        // The good line still draws. That is the point of dropping rather than
        // throwing: one typo costs one card.
        self::assertCount(1, $layout->placements);
        self::assertSame('survivor', $layout->placements[0]->widget);

        self::assertCount(1, $logger->warnings);
        self::assertStringContainsString($expectedInLog, $logger->warnings[0]);
    }

    /** A `widgets:` that is a scalar, not a list — the commonest YAML slip. */
    #[Test]
    public function aWidgetsKeyThatIsNotAListMeansNoLayout(): void
    {
        self::assertTrue($this->provider(['widgets' => 'vfs.file-count'])->load()->isEmpty());
    }

    /** @param array<string, mixed>|null $raw */
    private function provider(?array $raw, ?NullTestLogger $logger = null): DashboardLayoutProvider
    {
        return new DashboardLayoutProvider(
            new class($raw) implements ConfigLoaderInterface {
                /** @param array<string, mixed>|null $raw */
                public function __construct(private readonly ?array $raw)
                {
                }

                public function load(string $type, string $id, ?string $themeSlug = null): ?array
                {
                    return $this->raw;
                }
            },
            $logger ?? new NullTestLogger(),
        );
    }
}

/**
 * Captures warnings with their placeholders filled, so a test can assert on the
 * message a maintainer would actually read in the log rather than on a template.
 */
final class NullTestLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ('warning' !== $level) {
            return;
        }

        $text = (string) $message;
        foreach ($context as $key => $value) {
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }

        $this->warnings[] = $text;
    }
}
