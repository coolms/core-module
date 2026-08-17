<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\ApiManifest;

use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Config\SupportedLocalesProvider;
use CoolMS\CoreModule\ApiManifest\ApiManifestBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F6 Phase 1 -- the builder threads Core-owned platform data
 * (supported locales + platform defaults) straight into the manifest,
 * without a contributor.
 *
 * This pins the new `platformDefaults` wiring; the manifest is what the
 * FE reads to render anonymous / pre-login views with the deployment's
 * real locale + formats.
 */
final class ApiManifestBuilderTest extends TestCase
{
    #[Test]
    public function manifestCarriesPlatformDefaults(): void
    {
        $platformDefaults = new PlatformDefaults(
            locale: 'uk',
            timezone: 'Europe/Kyiv',
            dateFormat: 'dd/MM/yyyy',
            timeFormat: '24h',
            weekStart: 'monday',
        );

        $builder = new ApiManifestBuilder(
            contributors: [],
            localesProvider: new SupportedLocalesProvider([['code' => 'uk', 'label' => 'Українська']]),
            platformDefaults: $platformDefaults,
        );

        $manifest = $builder->build('/api/v1');

        self::assertSame($platformDefaults, $manifest->platformDefaults);
        // The default locale, previously absent from the manifest, now
        // rides along on platformDefaults. (assertSame above narrows it
        // to non-null, so a plain -> here, not ?->.)
        self::assertSame('uk', $manifest->platformDefaults->locale);
        // Sibling Core data still flows.
        self::assertSame([['code' => 'uk', 'label' => 'Українська']], $manifest->supportedLocales);
    }
}
