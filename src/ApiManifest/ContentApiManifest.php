<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

/**
 * Content module URL manifest -- exposes the publish workflow endpoint.
 * Generic VFS endpoints (page CRUD, variant CRUD, content read/write)
 * are now served by `/api/v1/vfs/nodes` and are not surfaced here.
 */
final readonly class ContentApiManifest
{
    public function __construct(
        public string $variantPublishUrl, // pattern: /api/v1/content/variants/{id}/publish
        // Articles admin. The space list is surfaced here rather than
        // hard-coded in the client for the same reason Media and Document
        // do it: the FE must never assemble a VFS path itself -- it asks
        // for spaces by key and the registry decides what exists.
        // The five `articleXxxUrl` fields are GONE.
        // Advertising a URL is a promise the route exists; leaving them here
        // pointing at deleted endpoints would have made the manifest lie to
        // every client that reads it.
        //
        // Pages admin. Same rule as above — the client asks for
        // spaces by key and the registry decides what exists; it never
        // assembles `/home/{uuid}/pages` itself.
        public string $pageSpacesUrl = '', // GET /api/v1/content/pages/spaces
        public string $pageTypesUrl = '',  // GET /api/v1/content/page-types
    ) {
    }
}
