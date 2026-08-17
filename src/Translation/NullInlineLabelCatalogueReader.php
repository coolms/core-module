<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Translation;

use CoolMS\Core\Translation\InlineLabelCatalogueReaderInterface;

/**
 * Null-object fallback for {@see InlineLabelCatalogueReaderInterface}, bound by
 * {@see \CoolMS\CoreBundle\DependencyInjection\Compiler\TranslationCatalogueFallbackPass}
 * only when the I18n module is absent (its real impl, when present, wins).
 *
 * Without a catalogue there are no authored overrides, so reading them yields
 * an empty map — exactly the "nothing translated yet" state. Consumers (e.g.
 * the FieldDefinition editor pre-fill) then show source labels with blank
 * translation inputs, which is the correct single-locale experience. This is a
 * genuine no-op, NOT an error: reading is always safe.
 */
final class NullInlineLabelCatalogueReader implements InlineLabelCatalogueReaderInterface
{
    public function readChildLabels(object $parent, string $childKind, array $childIds, string $field): array
    {
        return [];
    }

    public function readLabels(object $parent, array $fields): array
    {
        return [];
    }
}
