<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Dashboard;

use CoolMS\Core\Dashboard\DashboardWidget;
use CoolMS\Core\Dashboard\DashboardWidgetProviderInterface;
use CoolMS\CoreModule\Dashboard\DashboardWidgetRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_map;

/**
 * The dashboard catalogue's three rules.
 *
 * Each one refuses something rather than passing it on, and each refusal is
 * cheaper here than the thing it prevents downstream — an ambiguous saved
 * layout, a card the client cannot draw, a tile that can only show a 403.
 */
#[CoversClass(DashboardWidgetRegistry::class)]
final class DashboardWidgetRegistryTest extends TestCase
{
    #[Test]
    public function itCollectsEveryProviderInOrder(): void
    {
        $registry = $this->registry([
            [$this->widget('a.one'), $this->widget('a.two')],
            [$this->widget('b.one')],
        ]);

        self::assertSame(['a.one', 'a.two', 'b.one'], $this->ids($registry));
    }

    /**
     * A layout is stored against the id, so two widgets sharing one would make
     * a saved dashboard ambiguous. First wins — which keeps the answer stable
     * when a module is added rather than re-ordering someone's dashboard.
     */
    #[Test]
    public function aDuplicateIdIsRefusedAndTheFirstWins(): void
    {
        $registry = $this->registry([
            [$this->widget('shared', label: 'Original')],
            [$this->widget('shared', label: 'Impostor')],
        ]);

        $widgets = $registry->forCurrentUser();
        self::assertCount(1, $widgets);
        self::assertSame('Original', $widgets[0]->label);
    }

    /**
     * A widget kind is a CONTRACT with the admin's renderer. A card it cannot
     * draw is worse than one that is absent, and drawing it as something else
     * would show a number meaning another thing.
     */
    #[Test]
    public function aWidgetKindTheClientCannotDrawIsRefused(): void
    {
        $registry = $this->registry([[
            $this->widget('good', kind: DashboardWidget::KIND_STAT),
            $this->widget('invented', kind: 'sparkline'),
            // A kind the platform INTENDS to draw but cannot yet is refused
            // just the same — see DashboardWidget::KINDS for why that is the
            // point rather than an oversight.
            $this->widget('premature', kind: DashboardWidget::KIND_CHART),
        ]]);

        self::assertSame(['good'], $this->ids($registry));
    }

    /** An empty tile is worse than an absent one. */
    #[Test]
    public function aWidgetIsHiddenFromAViewerWithoutItsRole(): void
    {
        $registry = $this->registry(
            [[
                $this->widget('open'),
                $this->widget('restricted', role: 'ROLE_ADMIN'),
            ]],
            $this->checker(granted: false),
        );

        self::assertSame(['open'], $this->ids($registry));
    }

    #[Test]
    public function theSameWidgetIsOfferedToAViewerWhoHasTheRole(): void
    {
        $registry = $this->registry(
            [[$this->widget('restricted', role: 'ROLE_ADMIN')]],
            $this->checker(granted: true),
        );

        self::assertSame(['restricted'], $this->ids($registry));
    }

    /**
     * Providers are walked ONCE. A dashboard asks more than one thing of this
     * within a request, and the answer cannot change in between.
     */
    #[Test]
    public function providersAreWalkedOnce(): void
    {
        $calls = 0;
        $provider = new class($calls) implements DashboardWidgetProviderInterface {
            public function __construct(private int &$calls)
            {
            }

            public function dashboardWidgets(): array
            {
                ++$this->calls;

                return [];
            }
        };

        $registry = new DashboardWidgetRegistry([$provider], $this->checker(granted: true));
        $registry->forCurrentUser();
        $registry->forCurrentUser();

        self::assertSame(1, $calls);
    }

    /** @param list<list<DashboardWidget>> $batches one per provider */
    private function registry(array $batches, ?AuthorizationCheckerInterface $authorization = null): DashboardWidgetRegistry
    {
        return new DashboardWidgetRegistry(
            array_map(
                static fn (array $widgets): DashboardWidgetProviderInterface => new class($widgets) implements DashboardWidgetProviderInterface {
                    /** @param list<DashboardWidget> $widgets */
                    public function __construct(private readonly array $widgets)
                    {
                    }

                    public function dashboardWidgets(): array
                    {
                        return $this->widgets;
                    }
                },
                $batches,
            ),
            $authorization ?? $this->checker(granted: true),
        );
    }

    /** @return list<string> */
    private function ids(DashboardWidgetRegistry $registry): array
    {
        return array_map(
            static fn (DashboardWidget $widget): string => $widget->id,
            $registry->forCurrentUser(),
        );
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        return new class($granted) implements AuthorizationCheckerInterface {
            public function __construct(private readonly bool $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return $this->granted;
            }
        };
    }

    private function widget(
        string $id,
        string $label = 'Widget',
        string $kind = DashboardWidget::KIND_STAT,
        ?string $role = null,
    ): DashboardWidget {
        return new DashboardWidget(
            id: $id,
            label: $label,
            icon: 'bi-graph-up',
            endpoint: '/api/v1/' . $id,
            valuePath: 'count',
            kind: $kind,
            requiredRole: $role,
        );
    }
}
