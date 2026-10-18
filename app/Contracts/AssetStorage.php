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
     * Store raw bytes (e.g. an AI-generated image, Step 16.2) under the given directory with a
     * random filename and the given extension. Same return shape as store().
     *
     * @return array{disk: string, path: string, url: string, mime_type: string|null, size: int}
     */
    public function storeContents(string $contents, string $mimeType, string $directory, string $extension): array;

    /**
     * Read a stored file's bytes, or null when it no longer exists.
     */
    public function contents(string $disk, string $path): ?string;

    /**
     * Resolve a reachable URL for a stored file. Step I-1.6: for a private disk this is a signed
     * URL that expires after `assets.url_ttl_minutes`.
     */
    public function url(string $disk, string $path): string;

    /**
     * Like `url()`, with an explicit lifetime in minutes — e.g. long enough for a publishing
     * vendor to fetch the file (`assets.vendor_url_ttl_minutes`). Public disks ignore it.
     */
    public function signedUrl(string $disk, string $path, int $minutes): string;

    /**
     * Remove a stored file.
     */
    public function delete(string $disk, string $path): void;
}
