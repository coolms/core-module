<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Dashboard;

use CoolMS\Core\Dashboard\DashboardPlacement;
use CoolMS\Core\Dashboard\DashboardWidget;
use CoolMS\Core\Dashboard\DashboardWidgetProviderInterface;
use CoolMS\CoreModule\Config\ConfigLoaderInterface;
use CoolMS\CoreModule\Config\ConfigWriterInterface;
use CoolMS\CoreModule\Dashboard\DashboardLayoutProvider;
use CoolMS\CoreModule\Dashboard\DashboardLayoutWriter;
use CoolMS\CoreModule\Dashboard\DashboardWidgetRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function array_map;

/**
 * Saving an arrangement.
 *
 * The tests that matter are the two about what a save must NOT do: invent
 * widths nobody chose, and delete an arrangement its author could not see.
 */
#[CoversClass(DashboardLayoutWriter::class)]
final class DashboardLayoutWriterTest extends TestCase
{
    #[Test]
    public function theSubmittedOrderIsWhatGetsStored(): void
    {
        $config = new RecordingConfigWriter();
        $writer = $this->writer($config, offered: ['a', 'b']);

        $where = $writer->save([new DashboardPlacement('b', columns: 6), new DashboardPlacement('a')]);

        self::assertSame('dashboard', $config->type);
        self::assertSame('main', $config->id);
        self::assertSame(
            ['widgets' => [['widget' => 'b', 'columns' => 6], ['widget' => 'a']]],
            $config->data,
        );
        self::assertSame('stored:dashboard/main', $where);
    }

    /**
     * ⚠️ A null width must NOT be written out as a number. "Keep the module's
     * width" and "make it four" are different instructions, and storing the
     * second when the first was meant freezes the card forever — a module that
     * later improves its own default is then overruled by a number nobody chose.
     */
    #[Test]
    public function aPlacementWithNoWidthStoresNoWidth(): void
    {
        $config = new RecordingConfigWriter();

        $this->writer($config, offered: ['a'])->save([new DashboardPlacement('a')]);

        self::assertSame(['widgets' => [['widget' => 'a']]], $config->data);
    }

    #[Test]
    public function aHiddenPlacementIsStoredAsHidden(): void
    {
        $config = new RecordingConfigWriter();

        $this->writer($config, offered: ['a'])->save([new DashboardPlacement('a', hidden: true)]);

        self::assertSame(['widgets' => [['widget' => 'a', 'hidden' => true]]], $config->data);
    }

    /**
     * ⚠️ THE data-loss test. The catalogue is filtered per viewer, so an admin
     * describes only the dashboard THEY can see. A plain replace would delete a
     * colleague's arrangement of the widgets they cannot — invisibly, since
     * neither can see the other's cards.
     */
    #[Test]
    public function placementsForWidgetsThisViewerCannotSeeSurviveTheSave(): void
    {
        $config = new RecordingConfigWriter();
        $writer = $this->writer(
            $config,
            offered: ['visible'],
            existing: ['widgets' => [
                ['widget' => 'restricted', 'columns' => 12],
                ['widget' => 'visible', 'columns' => 3],
            ]],
        );

        $writer->save([new DashboardPlacement('visible', columns: 6)]);

        self::assertSame(
            ['widgets' => [
                ['widget' => 'visible', 'columns' => 6],
                ['widget' => 'restricted', 'columns' => 12],
            ]],
            $config->data,
        );
    }

    /**
     * The one moment someone is present to be told. A typo saved quietly becomes
     * a card that never appears, with nothing to read but a log line.
     */
    #[Test]
    public function aWidgetNoModuleOffersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/typo\.widget/');

        $this->writer(new RecordingConfigWriter(), offered: ['a'])
            ->save([new DashboardPlacement('a'), new DashboardPlacement('typo.widget')]);
    }

    /**
     * A widget hidden from THIS viewer cannot be placed by them either — the
     * catalogue is the only thing a save is allowed to talk about, which is
     * what stops a hand-crafted PUT from re-adding a card the registry refused.
     */
    #[Test]
    public function aWidgetTheViewerCannotSeeCannotBePlaced(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->writer(new RecordingConfigWriter(), offered: ['visible'])
            ->save([new DashboardPlacement('restricted')]);
    }

    #[Test]
    public function resetDropsTheStoredLayout(): void
    {
        $config = new RecordingConfigWriter();

        self::assertTrue($this->writer($config, offered: [])->reset());
        self::assertSame('dashboard/main', $config->deleted);
    }

    /**
     * @param list<string>              $offered
     * @param array<string, mixed>|null $existing
     */
    private function writer(RecordingConfigWriter $config, array $offered, ?array $existing = null): DashboardLayoutWriter
    {
        return new DashboardLayoutWriter(
            $this->registry($offered),
            new DashboardLayoutProvider($this->configLoader($existing), new NullLogger()),
            $config,
        );
    }

    /** @param list<string> $ids */
    private function registry(array $ids): DashboardWidgetRegistry
    {
        $widgets = array_map(
            static fn (string $id): DashboardWidget => new DashboardWidget(
                id: $id,
                label: $id,
                icon: 'bi-graph-up',
                endpoint: '/api/v1/' . $id,
                valuePath: 'count',
            ),
            $ids,
        );

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

        return new DashboardWidgetRegistry([$provider], new class implements AuthorizationCheckerInterface {
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return true;
            }
        });
    }

    /** @param array<string, mixed>|null $existing */
    private function configLoader(?array $existing): ConfigLoaderInterface
    {
        return new class($existing) implements ConfigLoaderInterface {
            /** @param array<string, mixed>|null $existing */
            public function __construct(private readonly ?array $existing)
            {
            }

            public function load(string $type, string $id, ?string $themeSlug = null): ?array
            {
                return $this->existing;
            }
        };
    }
}

/** Remembers the one write or delete it was asked for. */
final class RecordingConfigWriter implements ConfigWriterInterface
{
    public ?string $type = null;
    public ?string $id = null;

    /** @var array<string, mixed>|null */
    public ?array $data = null;

    public ?string $deleted = null;

    public function canWrite(string $type, string $id): bool
    {
        return true;
    }

    public function write(string $type, string $id, array $data): string
    {
        $this->type = $type;
        $this->id = $id;
        $this->data = $data;

        return 'stored:' . $type . '/' . $id;
    }

    public function delete(string $type, string $id): bool
    {
        $this->deleted = $type . '/' . $id;

        return true;
    }
}
