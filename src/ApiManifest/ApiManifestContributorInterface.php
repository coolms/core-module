<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

/**
 * Allows modules to contribute a typed section to the API manifest
 * returned by GET /api/v1/theme/config.
 *
 * Implementations are tagged 'coolms.api_manifest_contributor' via
 * registerForAutoconfiguration() in Core's DI Extension and collected
 * by ApiManifestBuilder through #[AutowireIterator].
 *
 * Return null when the module is not available or has no routes to expose.
 */
interface ApiManifestContributorInterface
{
    /**
     * Return the section key and its value, or null to contribute nothing.
     *
     * @return array{string, AuthApiManifest|SectionApiManifest|NaviApiManifest|ContentApiManifest|DataGridApiManifest|TerminalApiManifest|MediaApiManifest|DocumentApiManifest|VfsApiManifest|DynamicEntityApiManifest|object}|null
     */
    public function contribute(): ?array;
}
