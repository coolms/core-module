<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Config\SupportedLocalesProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Assembles ApiManifest from all registered ApiManifestContributorInterface implementations.
 *
 * Contributors are collected via the 'coolms.api_manifest_contributor' DI tag,
 * added through registerForAutoconfiguration() in Core's DI Extension.
 *
 * Two pieces of Core-owned platform data — `SupportedLocalesProvider`
 * and `PlatformDefaults` — are injected DIRECTLY rather than via the
 * contributor tag: they are Core's own and need no module indirection.
 */
final class ApiManifestBuilder
{
    /**
     * @param iterable<ApiManifestContributorInterface> $contributors
     */
    public function __construct(
        #[AutowireIterator('coolms.api_manifest_contributor')]
        private readonly iterable $contributors,
        private readonly SupportedLocalesProvider $localesProvider,
        private readonly PlatformDefaults $platformDefaults,
    ) {
    }

    public function build(string $apiBase): ApiManifest
    {
        $sections = [];

        foreach ($this->contributors as $contributor) {
            $contribution = $contributor->contribute();
            if (null !== $contribution) {
                [$key, $value] = $contribution;
                $sections[$key] = $value;
            }
        }

        /** @var ?AuthApiManifest $auth */
        $auth = $sections['auth'] ?? null;
        /** @var ?SectionApiManifest $sectionManifest */
        $sectionManifest = $sections['sections'] ?? null;
        /** @var ?NaviApiManifest $navi */
        $navi = $sections['navi'] ?? null;
        /** @var ?ContentApiManifest $content */
        $content = $sections['content'] ?? null;
        /** @var ?DataGridApiManifest $dataGrid */
        $dataGrid = $sections['dataGrid'] ?? null;
        /** @var ?TerminalApiManifest $terminal */
        $terminal = $sections['terminal'] ?? null;
        /** @var ?MediaApiManifest $media */
        $media = $sections['media'] ?? null;
        /** @var ?DocumentApiManifest $document */
        $document = $sections['document'] ?? null;
        /** @var ?VfsApiManifest $vfs */
        $vfs = $sections['vfs'] ?? null;
        /** @var ?DynamicEntityApiManifest $dynamicEntity */
        $dynamicEntity = $sections['dynamicEntity'] ?? null;

        return new ApiManifest(
            apiBase: $apiBase,
            configBase: $apiBase . '/config',
            auth: $auth,
            sections: $sectionManifest,
            navi: $navi,
            content: $content,
            dataGrid: $dataGrid,
            terminal: $terminal,
            media: $media,
            document: $document,
            vfs: $vfs,
            dynamicEntity: $dynamicEntity,
            identity: $sections['identity'] ?? null,
            supportedLocales: $this->localesProvider->all(),
            platformDefaults: $this->platformDefaults,
            domainExplorer: $sections['domainExplorer'] ?? null,
            editor: $sections['editor'] ?? null,
            viewers: $sections['viewers'] ?? null,
        );
    }
}
