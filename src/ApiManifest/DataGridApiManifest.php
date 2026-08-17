<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class DataGridApiManifest
{
    public function __construct(
        public string $configBase, // /api/v1/datagrids
    ) {
    }
}
