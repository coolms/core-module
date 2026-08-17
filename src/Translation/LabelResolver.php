<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Translation;

use CoolMS\Core\Translation\LabelResolverInterface;
use CoolMS\Core\Translation\Translatable;
use CoolMS\Core\Translation\TranslatableMisconfigurationException;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Default `LabelResolverInterface` impl.
 *
 * Two collaborators:
 *  - `TranslatorInterface` (Symfony). When that's decorated by F5.b's
 *    `VfsOverlayingTranslator`, the lookup transparently consults VFS
 *    XLIFF overrides before bundled `.xlf` files. We don't reach for
 *    the loader directly; the decorated translator IS the read path.
 *  - PHP reflection for `#[Translatable]` attribute discovery + field
 *    presence checks.
 *
 * **Caching.** Reflection on PHP attributes is not cheap relative to a
 * `t()` call. Resolved class metadata (domain, snake-cased short name,
 * field allowlist) is memoised per-class for the resolver's lifetime;
 * the resolver is autowired as a singleton so cache lifetime = request
 * lifetime.
 *
 * **Domain derivation.** When `#[Translatable]` omits an explicit
 * `domain`, the resolver derives one from the leading namespace segment
 * after `App\`. a `CamelCase` module segment -> `camel_case`,
 * a `Form` module class -> `form`. Matches the existing bundled
 * catalogue convention (`translations/form.{locale}.xlf`). Non-`App\`
 * classes fall through to `messages` (Symfony's default) -- third-party
 * library types are not expected to be `#[Translatable]` but the path
 * is safe.
 *
 * **Key shape.** `{snake_case_short_name}.{identifier}.{field}` -- e.g.
 * `field_definition.color.label`. Stable, predictable, and what the
 * future inline-labels-to-XLIFF bridge writes against.
 *
 * **Source-value fallback.** Symfony's translator returns the key
 * unchanged when no translation exists in any catalogue (after the
 * fallback locale chain runs). We detect that and return the literal
 * source value off the definition instead. Operators see the source
 * text in missing-translation slots rather than a key like
 * `field_definition.color.label`.
 */
final class LabelResolver implements LabelResolverInterface
{
    /** @var array<class-string, ResolvedTranslatable> */
    private array $cache = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function resolve(object $definition, string $field, ?string $locale = null): string
    {
        $resolved = $this->resolveClass($definition::class);
        $this->ensureFieldIsListed($definition::class, $resolved, $field);

        $key = $this->buildKey($resolved, $this->deriveIdentifier($definition), $field);
        $translated = $this->translator->trans($key, [], $resolved->domain, $locale);

        // Symfony returns the key unchanged when no translation exists.
        // That signal is our fallback trigger -- the source value on the
        // entity itself becomes the displayed label. Comparing to the
        // key (not to the empty string) is important: an operator might
        // legitimately translate something to the empty string for a
        // particular locale, and we shouldn't override that.
        if ($translated === $key) {
            return $this->readSourceValue($definition, $field);
        }

        return $translated;
    }

    public function keyFor(object $definition, string $field): string
    {
        $resolved = $this->resolveClass($definition::class);
        $this->ensureFieldIsListed($definition::class, $resolved, $field);

        return $this->buildKey($resolved, $this->deriveIdentifier($definition), $field);
    }

    public function keyForChild(object $parent, string $childKind, string $childId, string $field): string
    {
        $resolved = $this->resolveClass($parent::class);
        $this->ensureChildFieldIsListed($parent::class, $resolved, $childKind, $field);

        return $this->buildChildKey(
            $resolved,
            $this->deriveIdentifier($parent),
            $childKind,
            $childId,
            $field,
        );
    }

    public function resolveChild(
        object $parent,
        string $childKind,
        string $childId,
        string $field,
        string $sourceValue,
        ?string $locale = null,
    ): string {
        $resolved = $this->resolveClass($parent::class);
        $this->ensureChildFieldIsListed($parent::class, $resolved, $childKind, $field);

        $key = $this->buildChildKey($resolved, $this->deriveIdentifier($parent), $childKind, $childId, $field);
        $translated = $this->translator->trans($key, [], $resolved->domain, $locale);

        // Same source-value fallback contract as resolve(): Symfony echoes
        // the key when no translation exists. The child's source value
        // can't be reflected off the parent, so the caller supplies it.
        return $translated === $key ? $sourceValue : $translated;
    }

    public function domainFor(object $definition): string
    {
        // resolveClass throws notTranslatable() when the class carries
        // no #[Translatable]; the domain is class-level metadata, so no
        // field validation here.
        return $this->resolveClass($definition::class)->domain;
    }

    /** @param class-string $class */
    private function resolveClass(string $class): ResolvedTranslatable
    {
        if (isset($this->cache[$class])) {
            return $this->cache[$class];
        }

        $rc = new ReflectionClass($class);
        $attrs = $rc->getAttributes(Translatable::class);
        if ([] === $attrs) {
            throw TranslatableMisconfigurationException::notTranslatable($class);
        }

        $attr = $attrs[0]->newInstance();
        $domain = $attr->domain ?? $this->deriveDomain($class);
        $shortName = $this->snakeCase($rc->getShortName());

        return $this->cache[$class] = new ResolvedTranslatable(
            domain: $domain,
            shortName: $shortName,
            fields: $attr->fields,
            children: $attr->children,
        );
    }

    /** @param class-string $class */
    private function ensureFieldIsListed(string $class, ResolvedTranslatable $resolved, string $field): void
    {
        if (in_array($field, $resolved->fields, true)) {
            return;
        }

        throw TranslatableMisconfigurationException::fieldNotListed($class, $field, $resolved->fields);
    }

    private function buildKey(ResolvedTranslatable $resolved, string $identifier, string $field): string
    {
        return sprintf('%s.%s.%s', $resolved->shortName, $identifier, $field);
    }

    /**
     * Validate that `$childKind` is a declared translatable child of the
     * class and `$field` is one of its listed fields. The attribute is the
     * contract: an undeclared child kind or field throws even if the data
     * happens to carry it.
     */
    private function ensureChildFieldIsListed(
        string $class,
        ResolvedTranslatable $resolved,
        string $childKind,
        string $field,
    ): void {
        $childFields = $resolved->children[$childKind] ?? null;
        if (null !== $childFields && in_array($field, $childFields, true)) {
            return;
        }

        throw TranslatableMisconfigurationException::childFieldNotListed($class, $childKind, $field, $resolved->children);
    }

    private function buildChildKey(
        ResolvedTranslatable $resolved,
        string $parentId,
        string $childKind,
        string $childId,
        string $field,
    ): string {
        return sprintf('%s.%s.%s.%s.%s', $resolved->shortName, $parentId, $childKind, $childId, $field);
    }

    /**
     * Resolution chain for the definition's identifier (used in the
     * catalogue key). Tries, in order:
     *   1. `getId()` method (covers most Domain entities that follow
     *      the project's getter convention).
     *   2. `$id` public property.
     *   3. `$slug` public property (NaviNode, SiteSection, etc).
     *   4. Throws `noIdentifier()`.
     *
     * Stringification: UUIDs stringify to canonical form; arbitrary
     * objects with a `__toString()` work too. Numeric ids cast to
     * decimal strings.
     */
    private function deriveIdentifier(object $definition): string
    {
        if (method_exists($definition, 'getId')) {
            $id = $definition->getId();
            if (null !== $id) {
                return $this->stringify($id, $definition::class);
            }
        }
        if (property_exists($definition, 'id') && isset($definition->id)) {
            return $this->stringify($definition->id, $definition::class);
        }
        if (property_exists($definition, 'slug') && isset($definition->slug)) {
            return $this->stringify($definition->slug, $definition::class);
        }

        throw TranslatableMisconfigurationException::noIdentifier($definition::class);
    }

    /** @param class-string $class */
    private function stringify(mixed $value, string $class): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        throw TranslatableMisconfigurationException::noIdentifier($class);
    }

    private function readSourceValue(object $definition, string $field): string
    {
        if (!property_exists($definition, $field)) {
            throw TranslatableMisconfigurationException::fieldDoesNotExist($definition::class, $field);
        }
        $value = $definition->{$field};

        return is_string($value) ? $value : '';
    }

    /** @param class-string $class */
    private function deriveDomain(string $class): string
    {
        // the segment after the app prefix, lowercased. Falls through
        // to Symfony's default `messages` for non-App\ classes so a
        // third-party type carrying #[Translatable] still works (rare
        // but possible; we accept it).
        $parts = explode('\\', $class);
        if ('App' !== $parts[0] || !isset($parts[1])) {
            return 'messages';
        }

        return $this->snakeCase($parts[1]);
    }

    /** Convert StudlyCase / PascalCase to snake_case. */
    private function snakeCase(string $name): string
    {
        // 'FooBar' -> 'foo_bar', 'I18n' -> 'i18n' (leading digit-run safe).
        $snake = preg_replace('/(?<!^)([A-Z])/', '_$1', $name) ?? $name;

        return strtolower($snake);
    }
}
