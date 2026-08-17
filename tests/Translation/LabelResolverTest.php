<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Translation;

use CoolMS\Core\Translation\Translatable;
use CoolMS\Core\Translation\TranslatableMisconfigurationException;
use CoolMS\CoreModule\Translation\LabelResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Application-layer LabelResolver impl.
 *
 * Tests the FOUR things the impl decides on top of the Domain contract:
 *
 *   1. Key derivation: `{snake_short_name}.{id|slug}.{field}`.
 *   2. Domain derivation: explicit attribute > namespace segment > fallback.
 *   3. Source-value fallback when the translator returns the key unchanged.
 *   4. Identifier chain: getId() -> $id -> $slug -> throw.
 *
 * The Symfony translator is stubbed; we never spin up the real
 * service. Calls go through `TranslatorInterface::trans()` -- whatever
 * the resolver hands the translator is what we assert. This isolates
 * the resolver's behaviour from whatever decoration chain (VFS
 * overlay, fallback chain, ICU formatter) runs in production.
 */
final class LabelResolverTest extends TestCase
{
    // ── Happy path: key derivation + translation flow ─────────────────────────

    #[Test]
    public function resolveReturnsTranslationWhenCatalogueHasIt(): void
    {
        $translator = $this->translatorReturning('Колір');
        $resolver = new LabelResolver($translator);

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');
        $result = $resolver->resolve($definition, 'label', 'uk');

        self::assertSame('Колір', $result);
    }

    #[Test]
    public function resolveFallsBackToSourceValueWhenTranslatorReturnsKey(): void
    {
        // Symfony's documented behaviour: missing translation -> trans()
        // returns the key unchanged. The resolver detects that and
        // returns the literal source value off the entity. Operators
        // see "Color" in the admin even when no UK translation exists,
        // not the raw key. Stub echoes the key arg verbatim to model
        // that behaviour without hardcoding the resolver's key shape.
        $translator = $this->translatorEchoingKey();
        $resolver = new LabelResolver($translator);

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');
        $result = $resolver->resolve($definition, 'label', 'uk');

        self::assertSame('Color', $result);
    }

    #[Test]
    public function resolveReturnsEmptyStringWhenSourceValueIsAlsoEmpty(): void
    {
        // Edge: source value is empty AND translator returns the key.
        // We return empty string (the source value), not the key.
        $translator = $this->translatorEchoingKey();
        $resolver = new LabelResolver($translator);

        $definition = new FieldDefinitionFixture(id: 'color', label: '');
        $result = $resolver->resolve($definition, 'label', 'uk');

        self::assertSame('', $result);
    }

    #[Test]
    public function resolvePassesThroughLocaleNullToTranslator(): void
    {
        // When the caller passes null, Symfony's translator falls back
        // to its current-request locale. We must NOT inject a locale
        // for "convenience" -- that would override request-scoped
        // locale state. Verify by capturing what the resolver hands the
        // translator.
        $capturedLocale = 'sentinel';
        $translator = $this->translatorCapturingLocale($capturedLocale);
        $resolver = new LabelResolver($translator);

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');
        $resolver->resolve($definition, 'label');

        self::assertNull($capturedLocale);
    }

    // ── Key derivation ────────────────────────────────────────────────────────

    #[Test]
    public function keyForBuildsSnakeCasedShortClassNameDotIdDotField(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');
        $key = $resolver->keyFor($definition, 'label');

        self::assertSame('field_definition_fixture.color.label', $key);
    }

    #[Test]
    public function keyForFallsBackFromGetIdToIdPropertyToSlugProperty(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        // getId() takes precedence
        $withGetId = new GetIdFixture('opt-1', 'Option');
        self::assertSame('get_id_fixture.opt-1.label', $resolver->keyFor($withGetId, 'label'));

        // $id property when no getId()
        $withIdProp = new IdPropertyFixture('opt-2', 'Option');
        self::assertSame('id_property_fixture.opt-2.label', $resolver->keyFor($withIdProp, 'label'));

        // $slug property when no $id
        $withSlug = new SlugFixture('color', 'Color');
        self::assertSame('slug_fixture.color.label', $resolver->keyFor($withSlug, 'label'));
    }

    #[Test]
    public function keyForRaisesWhenNoIdentifierExposed(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $this->expectException(TranslatableMisconfigurationException::class);
        $this->expectExceptionMessageMatches('/identifier/');
        $resolver->keyFor(new NoIdFixture('Foo'), 'label');
    }

    // ── Inline-child seam: keyForChild() / resolveChild() ─────────────────────

    #[Test]
    public function keyForChildBuildsParentKeyPlusChildKindIdField(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $parent = new FieldDefWithOptionsFixture(id: 'status', label: 'Status');
        $key = $resolver->keyForChild($parent, 'option', 'open', 'label');

        self::assertSame('field_def_with_options_fixture.status.option.open.label', $key);
    }

    #[Test]
    public function resolveChildReturnsTranslationWhenCatalogueHasIt(): void
    {
        $resolver = new LabelResolver($this->translatorReturning('Відкрито'));

        $parent = new FieldDefWithOptionsFixture(id: 'status', label: 'Status');
        $result = $resolver->resolveChild($parent, 'option', 'open', 'label', 'Open', 'uk');

        self::assertSame('Відкрито', $result);
    }

    #[Test]
    public function resolveChildFallsBackToSuppliedSourceValueWhenTranslatorReturnsKey(): void
    {
        // The inline child is not reflectable off the parent, so the caller
        // passes the source value. When the translator echoes the key
        // (no translation), that supplied source value is returned.
        $resolver = new LabelResolver($this->translatorEchoingKey());

        $parent = new FieldDefWithOptionsFixture(id: 'status', label: 'Status');
        $result = $resolver->resolveChild($parent, 'option', 'open', 'label', 'Open', 'uk');

        self::assertSame('Open', $result);
    }

    #[Test]
    public function keyForChildRaisesWhenChildKindNotDeclared(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $parent = new FieldDefWithOptionsFixture(id: 'status', label: 'Status');

        $this->expectException(TranslatableMisconfigurationException::class);
        $this->expectExceptionMessageMatches('/badge/');
        $resolver->keyForChild($parent, 'badge', 'open', 'label');
    }

    #[Test]
    public function keyForChildRaisesWhenChildFieldNotDeclaredForKind(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $parent = new FieldDefWithOptionsFixture(id: 'status', label: 'Status');

        $this->expectException(TranslatableMisconfigurationException::class);
        $this->expectExceptionMessageMatches('/description/');
        $resolver->keyForChild($parent, 'option', 'open', 'description');
    }

    // ── Domain derivation ─────────────────────────────────────────────────────

    #[Test]
    public function explicitAttributeDomainWinsOverNamespaceDerivation(): void
    {
        $captured = '';
        $translator = $this->translatorCapturingDomain($captured);
        $resolver = new LabelResolver($translator);

        $definition = new ExplicitDomainFixture(id: 'x', label: 'Foo');
        $resolver->resolve($definition, 'label', 'en');

        self::assertSame('dynamic_entity', $captured);
    }

    /**
     * A class outside `App\` falls through to Symfony's default domain.
     *
     * The segment-after-`App\` rule is the consuming application's namespace
     * convention, so it is asserted app-side (LabelResolverAppDomainTest); a
     * fixture in this package cannot exercise it without pretending to live in
     * someone else's namespace. What the package guarantees is this fall-through
     * -- and it is what every third-party `#[Translatable]` type will hit.
     */
    #[Test]
    public function defaultsDomainToMessagesOutsideTheAppNamespace(): void
    {
        $captured = '';
        $translator = $this->translatorCapturingDomain($captured);
        $resolver = new LabelResolver($translator);

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');
        $resolver->resolve($definition, 'label', 'en');

        self::assertSame('messages', $captured);
    }

    // ── domainFor() (write-side bridge needs the catalogue file stem) ──────────

    #[Test]
    public function domainForReturnsExplicitAttributeDomain(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $definition = new ExplicitDomainFixture(id: 'x', label: 'Foo');
        self::assertSame('dynamic_entity', $resolver->domainFor($definition));
    }

    #[Test]
    public function domainForFallsBackToMessagesOutsideTheAppNamespace(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');
        self::assertSame('messages', $resolver->domainFor($definition));
    }

    #[Test]
    public function domainForRaisesWhenClassIsNotTranslatable(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $this->expectException(TranslatableMisconfigurationException::class);
        $this->expectExceptionMessageMatches('/#\\[Translatable\\]/');
        $resolver->domainFor(new PlainFixture('foo'));
    }

    // ── Exception cases ───────────────────────────────────────────────────────

    #[Test]
    public function resolveRaisesWhenClassIsNotTranslatable(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $this->expectException(TranslatableMisconfigurationException::class);
        $this->expectExceptionMessageMatches('/#\\[Translatable\\]/');
        $resolver->resolve(new PlainFixture('foo'), 'label');
    }

    #[Test]
    public function resolveRaisesWhenFieldNotInAttributeList(): void
    {
        $resolver = new LabelResolver($this->translatorReturning(''));

        $definition = new FieldDefinitionFixture(id: 'color', label: 'Color');

        $this->expectException(TranslatableMisconfigurationException::class);
        $this->expectExceptionMessageMatches('/description/');
        $resolver->resolve($definition, 'description');
    }

    // ── Caching ───────────────────────────────────────────────────────────────

    #[Test]
    public function reusesReflectionResultAcrossCallsForSameClass(): void
    {
        // Use the same resolver instance + same class for many calls.
        // If reflection were re-run each time, the cost would be
        // observable in any profiling pass; functionally we can only
        // assert the result is stable + identifiers vary by row.
        $resolver = new LabelResolver($this->translatorReturning(''));

        $a = new FieldDefinitionFixture(id: 'color', label: 'Color');
        $b = new FieldDefinitionFixture(id: 'size', label: 'Size');

        self::assertSame('field_definition_fixture.color.label', $resolver->keyFor($a, 'label'));
        self::assertSame('field_definition_fixture.size.label', $resolver->keyFor($b, 'label'));
        self::assertSame('field_definition_fixture.color.label', $resolver->keyFor($a, 'label'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function translatorReturning(string $returnValue): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willReturn($returnValue);

        return $stub;
    }

    /**
     * Models Symfony's "no translation found" behaviour: trans() returns
     * the input key verbatim. Used by source-value-fallback tests where
     * we don't want to couple the test to the resolver's key shape.
     */
    private function translatorEchoingKey(): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willReturnCallback(
            static fn (string $key): string => $key,
        );

        return $stub;
    }

    private function translatorCapturingLocale(?string &$capture): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willReturnCallback(
            static function (string $key, array $params, ?string $domain, ?string $locale) use (&$capture): string {
                $capture = $locale;

                return $key;
            },
        );

        return $stub;
    }

    private function translatorCapturingDomain(string &$capture): TranslatorInterface
    {
        $stub = $this->createStub(TranslatorInterface::class);
        $stub->method('trans')->willReturnCallback(
            static function (string $key, array $params, ?string $domain, ?string $locale) use (&$capture): string {
                $capture = (string) $domain;

                return $key;
            },
        );

        return $stub;
    }
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

#[Translatable(fields: ['label'])]
final class FieldDefinitionFixture
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}

#[Translatable(fields: ['label'], children: ['option' => ['label']])]
final class FieldDefWithOptionsFixture
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}

#[Translatable(fields: ['label'], domain: 'dynamic_entity')]
final class ExplicitDomainFixture
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}

#[Translatable(fields: ['label'])]
final class GetIdFixture
{
    public function __construct(
        private readonly string $id,
        public string $label,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }
}

#[Translatable(fields: ['label'])]
final class IdPropertyFixture
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}

#[Translatable(fields: ['label'])]
final class SlugFixture
{
    public function __construct(
        public string $slug,
        public string $label,
    ) {
    }
}

#[Translatable(fields: ['label'])]
final class NoIdFixture
{
    public function __construct(
        public string $label,
    ) {
    }
}

final class PlainFixture
{
    public function __construct(public string $label)
    {
    }
}
