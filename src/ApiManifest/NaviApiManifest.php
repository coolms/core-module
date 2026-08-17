<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class NaviApiManifest
{
    public function __construct(
        public string $treesList,
        public string $treesCreate,
        public string $treesItem,       // pattern: /api/v1/navi/trees/{slug}
        public string $nodesList,
        public string $nodesCreate,
        public string $nodesItem,       // pattern: /api/v1/navi/nodes/{id}
        public string $nodesReorder,    // PATCH /api/v1/navi/nodes/reorder
        public string $nodesMove,       // pattern: /api/v1/navi/nodes/{id}/move
        public string $nodesByTree,     // pattern: /api/v1/navi/trees/{slug}/nodes
        public ?string $treesFormId = null,
        public ?string $nodesFormId = null,
        public ?string $adminNavGraph = null,   // /api/v1/navi/trees/navi.admin/graph
        public ?string $topbarNavGraph = null,  // /api/v1/navi/trees/navi.admin.topbar/graph
        public ?string $toolbarVfsGraph = null, // /api/v1/navi/trees/navi.toolbar.vfs/graph
        public ?string $graphBySlug = null,     // URL pattern: /api/v1/navi/trees/{slug}/graph
    ) {
    }
}
