<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class MediaApiManifest
{
    public function __construct(
        public string $listUrl,                    // GET   /media
        public string $itemUrl,                    // pattern: /api/v1/media/{id}
        public string $permissionsUrl,             // pattern: /api/v1/media/{id}/permissions
        public string $regenerateUrl,              // pattern: /api/v1/media/{id}/regenerate
        public string $assetUrl,                   // pattern: /media/assets/{id}
        public string $collectionsUrl,             // GET   /api/v1/media/collections
        public string $collectionsCreateUrl,       // POST  /api/v1/media/collections
        public string $collectionPermissionsUrl,   // PATCH /api/v1/media/collections/permissions
        public string $moveUrl = '',               // pattern: /api/v1/media/{id}/move
        public string $spacesUrl = '',             // GET   /api/v1/media/spaces
    ) {
    }
}
