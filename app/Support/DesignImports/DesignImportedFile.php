<?php

namespace App\Support\DesignImports;

/**
 * Step M-5.9 — a downloaded file's bytes and metadata, ready to go through the normal private asset
 * pipeline (`AssetStorage::storeContents` + the media probe).
 */
final readonly class DesignImportedFile
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $filename,
        public string $extension,
    ) {}
}
