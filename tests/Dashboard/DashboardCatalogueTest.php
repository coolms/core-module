<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Dashboard;

use CoolMS\Core\Dashboard\DashboardWidget;
use CoolMS\Core\Dashboard\DashboardWidgetProviderInterface;
use CoolMS\Core\Dashboard\PlacedWidget;
use CoolMS\CoreModule\Config\ConfigLoaderInterface;
use CoolMS\CoreModule\Dashboard\DashboardCatalogue;
use CoolMS\CoreModule\Dashboard\DashboardLayoutProvider;
use CoolMS\CoreModule\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_map;

/**
 * Which dashboard a widget belongs to.
 *
 * A section is a PARTITION, not a filter: a widget appears on exactly one
 * dashboard. A filter would let the same card show in two places and be
 * arranged differently in each, which makes "hide this" an ambiguous
 * instruction and a saved layout an ambiguous answer.
 */
#[CoversClass(DashboardCatalogue::class)]
final class DashboardCatalogueTest extends TestCase
{
    #[Test]
    public function theMainDashboardShowsONLYUngroupedWidgets(): void
    {
        $catalogue = $this->catalogue(['a' => null, 'b' => 'content', 'c' => null]);

        self::assertSame(['a', 'c'], $this->ids($catalogue->forCurrentUser()));
    }

    #[Test]
    public function aSectionShowsOnlyItsOwnWidgets(): void
    {
        $catalogue = $this->catalogue(['a' => null, 'b' => 'content', 'c' => 'identity', 'd' => 'content']);

        self::assertSame(['b', 'd'], $this->ids($catalogue->forCurrentUser('content')));
    }

    #[Test]
    public function anUnknownSectionIsEmptyRatherThanEverything(): void
    {
        // The failure this guards is the classic one: a filter that silently
        // matches nothing and falls back to "show all".
        self::assertSame([], $this->ids($this->catalogue(['a' => null])->forCurrentUser('nope')));
    }

    /** The switcher's contents. A section nobody has widgets for is not one. */
    #[Test]
    public function theSectionListIsTheDistinctGroupsInCatalogueOrder(): void
    {
        $catalogue = $this->catalogue(['a' => 'growth', 'b' => null, 'c' => 'content', 'd' => 'growth']);

        self::assertSame(['growth', 'content'], $catalogue->sectionsForCurrentUser());
    }

    #[Test]
    public function noGroupedWidgetsMeansNoSections(): void
    {
        self::assertSame([], $this->catalogue(['a' => null])->sectionsForCurrentUser());
    }

    /**
     * ⚠️ Each dashboard reads its OWN layout, keyed by the section name. Sharing
     * one would make arranging Content silently re-order the main dashboard.
     */
    #[Test]
    public function eachDashboardLoadsItsOwnLayout(): void
    {
        $loader = new RecordingConfigLoader();
        $catalogue = $this->catalogue(['a' => null, 'b' => 'content'], $loader);

        $catalogue->forCurrentUser();
        $catalogue->forCurrentUser('content');

        self::assertSame(['main', 'content'], $loader->asked);
    }

    /**
     * @param array<string, string|null> $groups widget id to its group
     */
    private function catalogue(array $groups, ?RecordingConfigLoader $loader = null): DashboardCatalogue
    {
        $widgets = [];
        foreach ($groups as $id => $group) {
            $widgets[] = new DashboardWidget(
                id: $id,
                label: $id,
                icon: 'bi-graph-up',
                endpoint: '/api/v1/' . $id,
                valuePath: 'count',
                group: $group,
            );
        }

        $provider = new class($widgets) implements DashboardWidgetProviderInterface {
            /** @param list<DashboardWidget> $widgets */
            public function __construct(private readonly array $widgets)
            {
            }

            public function dashboardWidgets(): array
            {
                return $this->widgets;
            }
        };

        $registry = new DashboardWidgetRegistry([$provider], new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return true;
            }
        });

        return new DashboardCatalogue(
            $registry,
            new DashboardLayoutProvider($loader ?? new RecordingConfigLoader(), new NullLogger()),
        );
    }

    /**
     * @param list<PlacedWidget> $placed
     *
     * @return list<string>
     */
    private function ids(array $placed): array
    {
        return array_map(static fn (PlacedWidget $p): string => $p->widget->id, $placed);
    }
}

/** Remembers which layout ids were asked for, and has none of them. */
final class RecordingConfigLoader implements ConfigLoaderInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function load(string $type, string $id, ?string $themeSlug = null): ?array
    {
        $this->asked[] = $id;

        return null;
    }
}
