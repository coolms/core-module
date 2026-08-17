<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

final readonly class VfsApiManifest
{
    public function __construct(
        public string $fileContentUrl,   // GET/PUT /api/v1/vfs/files/content?path={path}
        public string $binaryWriteUrl,   // POST multipart /api/v1/vfs/files/binary
    ) {
    }
}
