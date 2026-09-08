<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * Abstracts where/how uploaded Asset files are stored, so the Upload-Post-style
 * signed-URL/S3 flow from spec §10 can be dropped in later (Phase 1+) without
 * touching any Action/Controller that stores or removes an asset file.
 */
interface AssetStorage
{
    /**
     * Store an uploaded file under the given directory and return its
     * disk/path/url/mime_type/size.
     *
     * @return array{disk: string, path: string, url: string, mime_type: string|null, size: int}
     */
    public function store(UploadedFile $file, string $directory): array;

    /**
     * Resolve a publicly reachable URL for a stored file.
     */
    public function url(string $disk, string $path): string;

    /**
     * Remove a stored file.
     */
    public function delete(string $disk, string $path): void;
}
