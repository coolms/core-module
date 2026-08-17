<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class SectionApiManifest
{
    public function __construct(
        public string $list,
        public string $create,
        public string $item,    // pattern: /api/v1/sections/{id}
        public string $update,  // pattern: /api/v1/sections/{id}
        public string $delete,  // pattern: /api/v1/sections/{id}
        public ?string $formId = null,
        public ?string $apply = null,   // POST /api/v1/sections/_apply (H9)
    ) {
    }
}
