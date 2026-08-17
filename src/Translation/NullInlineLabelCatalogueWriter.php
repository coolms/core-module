<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Translation;

use CoolMS\Core\Identity\UserInterface;
use CoolMS\Core\Translation\InlineLabelCatalogueWriterInterface;
use CoolMS\Core\Translation\TranslationCatalogueUnavailableException;

/**
 * Null-object fallback for {@see InlineLabelCatalogueWriterInterface}, bound by
 * {@see \CoolMS\CoreBundle\DependencyInjection\Compiler\TranslationCatalogueFallbackPass}
 * only when the I18n module is absent (its real impl, when present, wins).
 *
 * Unlike the reader fallback, this does NOT silently no-op: writing a
 * translation with no catalogue available would lose the operator's input.
 * Both methods raise {@see TranslationCatalogueUnavailableException} so the
 * failure is loud and explains the fix (install `coolms/i18n`). In a
 * single-locale deployment this is unreachable through the UI — the authoring
 * panel only renders when non-default locales exist — so it guards only the
 * direct-API misconfiguration path.
 */
final class NullInlineLabelCatalogueWriter implements InlineLabelCatalogueWriterInterface
{
    public function write(object $translatable, array $localizedLabels, UserInterface $actor): void
    {
        throw TranslationCatalogueUnavailableException::forWrite();
    }

    public function writeChildren(object $parent, string $childKind, array $childLabels, UserInterface $actor): void
    {
        throw TranslationCatalogueUnavailableException::forWrite();
    }
}
