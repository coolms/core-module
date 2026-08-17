<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Dashboard;

use CoolMS\Core\Dashboard\PlacedWidget;

use function in_array;

/**
 * The dashboard as it should be drawn: what this viewer may see, arranged the
 * way config says, for the main dashboard or for one section.
 *
 * Two steps that must stay in this order. The registry decides WHETHER a widget
 * is offered — a question about the viewer — and the layout decides only where
 * it goes and how wide. Applying the layout second is what makes it structurally
 * unable to reveal anything: it can reorder, resize and hide the list it is
 * handed, and there is no path by which it adds to it.
 *
 * ## Sections are a PARTITION, not a filter
 *
 * A widget's `group` decides which dashboard it belongs to, and it belongs to
 * exactly one: ungrouped widgets are the main dashboard, and a widget with
 * `group: content` appears on the Content section and NOWHERE else. A filter
 * would have let the same card show twice and be arranged differently in each
 * place, which makes "hide this" an ambiguous instruction.
 *
 * Each section keeps its own layout — the section name IS the layout id — so
 * arranging Content cannot disturb the main dashboard.
 */
final readonly class DashboardCatalogue
{
    public function __construct(
        private DashboardWidgetRegistry $widgets,
        private DashboardLayoutProvider $layouts,
    ) {
    }

    /**
     * @param string|null $section null for the main dashboard, else the `group`
     *                             a widget must declare to appear here
     *
     * @return list<PlacedWidget> in the order they should be rendered, hidden ones marked
     */
    public function forCurrentUser(?string $section = null): array
    {
        $offered = [];
        foreach ($this->widgets->forCurrentUser() as $widget) {
            if (($widget->group ?? null) === $section) {
                $offered[] = $widget;
            }
        }

        return $this->layouts->load($section ?? DashboardLayoutProvider::MAIN)->apply($offered);
    }

    /**
     * Every section this viewer has widgets for, in catalogue order.
     *
     * Returned alongside the widgets rather than from a route of its own,
     * because a client needs it to draw the switcher and a second endpoint
     * could disagree with the first. Sections with no widgets are absent by
     * construction — an empty section page is a tab that promises something and
     * then does not have it.
     *
     * @return list<string>
     */
    public function sectionsForCurrentUser(): array
    {
        $sections = [];
        foreach ($this->widgets->forCurrentUser() as $widget) {
            $group = $widget->group ?? null;
            if (null !== $group && !in_array($group, $sections, true)) {
                $sections[] = $group;
            }
        }

        return $sections;
    }
}
