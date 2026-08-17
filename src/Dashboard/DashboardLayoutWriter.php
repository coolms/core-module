<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Dashboard;

use CoolMS\Core\Dashboard\DashboardPlacement;
use CoolMS\CoreModule\Config\ConfigWriterInterface;
use InvalidArgumentException;

use function array_key_exists;
use function implode;
use function sprintf;

/**
 * Saves a dashboard arrangement.
 *
 * ## What a save may change, and what it cannot
 *
 * Order, width, hidden. Nothing else reaches storage: a placement carries a
 * widget ID and two numbers, so there is no shape in which a saved layout could
 * alter an `endpoint` or a `requiredRole`. That is deliberate — a config file
 * able to rewrite either would be an arrangement quietly overruling a module's
 * security, and the layout is applied AFTER the permission filter precisely so
 * it cannot.
 *
 * ## Saving is PARTIAL, because the catalogue is
 *
 * The registry hides widgets whose `requiredRole` the viewer lacks, so an admin
 * saving from the editor is describing the dashboard THEY can see. A plain
 * replace would then delete a colleague's arrangement of the widgets they
 * cannot — invisibly, since neither of them can see the other's cards.
 *
 * So placements for widgets outside this viewer's catalogue are KEPT, after the
 * submitted ones and in their existing relative order. It costs ten lines here
 * and is painful to retrofit once someone's arrangement is already gone.
 */
final readonly class DashboardLayoutWriter
{
    public function __construct(
        private DashboardWidgetRegistry $widgets,
        private DashboardLayoutProvider $layouts,
        private ConfigWriterInterface $config,
    ) {
    }

    /**
     * @param list<DashboardPlacement> $placements in the order they should appear
     *
     * @throws InvalidArgumentException when a placement names a widget this
     *                                  viewer is not offered
     *
     * @return string where the layout landed — a path, or `db://…`
     */
    public function save(array $placements, ?string $section = null): string
    {
        $dashboard = $section ?? DashboardLayoutProvider::MAIN;

        // Only the widgets THIS dashboard shows. Saving the Content section
        // must not be able to place a card that belongs to the main dashboard —
        // a widget lives on exactly one of them, and a save is a statement
        // about the one being arranged.
        $offered = [];
        foreach ($this->widgets->forCurrentUser() as $widget) {
            if (($widget->group ?? null) === $section) {
                $offered[$widget->id] = true;
            }
        }

        // Rejected rather than silently dropped at render time. This is the one
        // moment someone is present to be told: a typo in an id saved quietly
        // becomes a card that never appears, with nothing to read but the log.
        $unknown = [];
        foreach ($placements as $placement) {
            if (!array_key_exists($placement->widget, $offered)) {
                $unknown[] = $placement->widget;
            }
        }
        if ([] !== $unknown) {
            throw new InvalidArgumentException(sprintf('No installed module offers %s.', implode(', ', $unknown)));
        }

        $submitted = [];
        foreach ($placements as $placement) {
            $submitted[$placement->widget] = true;
        }

        $kept = [];
        foreach ($this->layouts->load($dashboard)->placements as $existing) {
            // Only what this viewer could not have been shown, and did not
            // resubmit — anything they CAN see, they have just spoken for.
            if (!array_key_exists($existing->widget, $offered) && !array_key_exists($existing->widget, $submitted)) {
                $kept[] = $existing;
            }
        }

        return $this->config->write(
            DashboardLayoutProvider::CONFIG_TYPE,
            $dashboard,
            ['widgets' => $this->toArray([...$placements, ...$kept])],
        );
    }

    /** Drop the saved arrangement so the catalogue's own order applies again. */
    public function reset(?string $section = null): bool
    {
        return $this->config->delete(
            DashboardLayoutProvider::CONFIG_TYPE,
            $section ?? DashboardLayoutProvider::MAIN,
        );
    }

    /**
     * @param list<DashboardPlacement> $placements
     *
     * @return list<array<string, mixed>>
     */
    private function toArray(array $placements): array
    {
        $out = [];
        foreach ($placements as $placement) {
            // Only what was actually decided. A null `columns` means "keep the
            // module's width" — writing it out as a number would freeze the
            // card at whatever it happened to be, which is the difference
            // between an arrangement and a snapshot.
            $entry = ['widget' => $placement->widget];
            if (null !== $placement->columns) {
                $entry['columns'] = $placement->columns;
            }
            if ($placement->hidden) {
                $entry['hidden'] = true;
            }

            $out[] = $entry;
        }

        return $out;
    }
}
