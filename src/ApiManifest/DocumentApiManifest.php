<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

/**
 * Document module's manifest section -- emitted by
 * the document module's manifest contributor
 * under the `document` key in `GET /api/v1/theme/config`.
 *
 * Today the only entry is `spacesUrl` (used by the FE
 * DocumentSpaceAccordion to fetch the per-user space list); the
 * struct is reserved so future Document-module URLs (template list,
 * generation, …) can land here without a new manifest section.
 */
final readonly class DocumentApiManifest
{
    public function __construct(
        public string $spacesUrl = '',     // GET /api/v1/document/spaces
    ) {
    }
}
