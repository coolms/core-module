<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

use CoolMS\Core\Config\PlatformDefaults;

final readonly class ApiManifest
{
    public function __construct(
        public string $apiBase,
        public ?string $configBase = null,
        public ?AuthApiManifest $auth = null,
        public ?SectionApiManifest $sections = null,
        public ?NaviApiManifest $navi = null,
        public ?ContentApiManifest $content = null,
        public ?DataGridApiManifest $dataGrid = null,
        public ?TerminalApiManifest $terminal = null,
        public ?MediaApiManifest $media = null,
        public ?DocumentApiManifest $document = null,
        public ?VfsApiManifest $vfs = null,
        public ?DynamicEntityApiManifest $dynamicEntity = null,
        public ?object $identity = null,
        /** @var array<array{code: string, label: string}> */
        public array $supportedLocales = [],
        /**
         * F6 Phase 1 -- platform-wide default user-facing
         * settings. Carries `locale` (the default locale, previously
         * absent from the manifest), `timezone`, `dateFormat`,
         * `timeFormat`, `weekStart`. The FE renders anonymous /
         * pre-login views against these.
         */
        public ?PlatformDefaults $platformDefaults = null,
        public ?object $domainExplorer = null,
        /** Editor toolbar contributor manifest — see the editor module's manifest. */
        public ?object $editor = null,
        /** F.7 viewer manifest — see the document module's viewer manifest. */
        public ?object $viewers = null,
    ) {
    }
}
