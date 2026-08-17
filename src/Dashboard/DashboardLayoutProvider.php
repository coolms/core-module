<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Dashboard;

use CoolMS\Core\Dashboard\DashboardLayout;
use CoolMS\Core\Dashboard\DashboardPlacement;
use CoolMS\CoreModule\Config\ConfigLoaderInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Reads a saved dashboard arrangement out of config DATA.
 *
 * ## Through the platform's config loader, not `glob` + `Yaml::parseFile`
 *
 * The file lives at `config/modules/core/dashboard/dashboard.yaml` and is found
 * the way every datagrid, navigraph tree and editor profile is found: by `type`
 * and `id` through {@see ConfigLoaderInterface}. That is not merely tidier — it
 * is the whole reason a read-only deployment can still have an editable
 * dashboard. The config seam is where "YAML when the directory is writable, the
 * database when it is not" belongs, ONCE, for every feature; a dashboard that
 * parsed its own file would have to grow its own copy of that fallback and
 * would be wrong on any host where the config directory is read-only.
 *
 * ## Everything here is UNTRUSTED, and that is deliberate
 *
 * A human edits this file. So a bad line is dropped and logged rather than
 * thrown, and the dashboard renders without it — the opposite of {@see
 * DashboardPlacement}, which refuses to exist in a wrong state because a bad
 * width THERE is a programmer's mistake in a module's own code.
 *
 * The two rules are not in tension, they are the layers doing their own jobs:
 * the value object guarantees it is never wrong, and this decides what to do
 * about input that is. A typo in a config file should cost one card, not the
 * page.
 */
final readonly class DashboardLayoutProvider
{
    /** The `type:` every dashboard layout file declares. */
    public const string CONFIG_TYPE = 'dashboard';

    /**
     * The main `/admin/dashboard`.
     *
     * An id rather than a bare filename because section dashboards are the next
     * thing this feeds — {@see \CoolMS\Core\Dashboard\DashboardWidget::$group}
     * exists for them — and they will each want their own arrangement under the
     * same `type`.
     */
    public const string MAIN = 'main';

    public function __construct(
        private ConfigLoaderInterface $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The arrangement saved for one dashboard, or an empty layout.
     *
     * An empty layout is the normal case, not a failure: with no file the
     * catalogue's own order stands, which is exactly what shipped before any of
     * this existed.
     */
    public function load(string $dashboard = self::MAIN): DashboardLayout
    {
        // ⚠️ No theme slug. The loader merges a theme override with
        // array_merge_recursive, which CONCATENATES lists — it would append a
        // second copy of every placement rather than replacing them. A layout
        // is a list, so it is not overridable that way, and asking for it would
        // silently duplicate cards.
        $raw = $this->config->load(self::CONFIG_TYPE, $dashboard);

        if (null === $raw || !is_array($raw['widgets'] ?? null)) {
            return DashboardLayout::none();
        }

        $placements = [];
        foreach ($raw['widgets'] as $index => $entry) {
            $placement = $this->placement($entry, $dashboard, (string) $index);
            if (null !== $placement) {
                $placements[] = $placement;
            }
        }

        return new DashboardLayout($placements);
    }

    private function placement(mixed $entry, string $dashboard, string $index): ?DashboardPlacement
    {
        if (!is_array($entry) || !is_string($entry['widget'] ?? null) || '' === $entry['widget']) {
            $this->warn($dashboard, $index, 'entry has no "widget" id');

            return null;
        }

        $columns = $entry['columns'] ?? null;
        if (null !== $columns && !is_int($columns)) {
            $this->warn($dashboard, $index, '"columns" must be a whole number');

            return null;
        }

        $hidden = $entry['hidden'] ?? false;
        if (!is_bool($hidden)) {
            $this->warn($dashboard, $index, '"hidden" must be true or false');

            return null;
        }

        try {
            return new DashboardPlacement($entry['widget'], $columns, $hidden);
        } catch (InvalidArgumentException $e) {
            // A width outside the grid. The value object's job is to refuse it;
            // this one's is to keep the other cards on the screen.
            $this->warn($dashboard, $index, $e->getMessage());

            return null;
        }
    }

    private function warn(string $dashboard, string $index, string $why): void
    {
        $this->logger->warning('Dashboard layout "{dashboard}": ignoring entry #{index} — {why}', [
            'dashboard' => $dashboard,
            'index' => $index,
            'why' => $why,
        ]);
    }
}
