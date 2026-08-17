<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Translation;

/**
 * Internal cache row for {@see LabelResolver}.
 *
 * Holds the precomputed metadata derived from a class's
 * `#[Translatable]` attribute + reflection: the translator domain, the
 * snake-cased short class name (used as the key prefix), and the
 * allowed-fields allowlist. Lives in `Application\` not `Domain\`
 * because it's a resolver implementation concern -- consumers reach
 * for `LabelResolverInterface`, never for this VO directly.
 */
final readonly class ResolvedTranslatable
{
    /**
     * @param list<string>                $fields   Allowlist for `LabelResolverInterface
     *                                              ::resolve()` / `keyFor()` calls. A
     *                                              field not in the list throws even if
     *                                              it exists as a property on the class
     *                                              -- the attribute is the contract,
     *                                              the property is implementation detail.
     * @param array<string, list<string>> $children Allowlist for the
     *                                              inline-child seam (`resolveChild()` /
     *                                              `keyForChild()`): map of
     *                                              `childKind => translatable field names`.
     *                                              Mirrors `Translatable::$children`. A
     *                                              (childKind, field) pair not present here
     *                                              throws.
     */
    public function __construct(
        public string $domain,
        public string $shortName,
        public array $fields,
        public array $children = [],
    ) {
    }
}
