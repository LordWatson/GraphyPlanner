<?php

namespace App\Services;

use App\Contracts\AssetStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stores Asset uploads on a Laravel filesystem disk (config('assets.disk'), the
 * "public" disk by default). Bound to `App\Contracts\AssetStorage` in
 * `AppServiceProvider` — swap the binding for an S3-backed implementation once
 * Phase 1/2 wires up signed-URL uploads (spec §10); no caller needs to change.
 */
class LocalAssetStorage implements AssetStorage
{
    /**
     * @return array{disk: string, path: string, url: string, mime_type: string|null, size: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $disk = config('assets.disk', 'public');

        $path = $file->store($directory, $disk);

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => $this->url($disk, $path),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
        ];
    }

    public function url(string $disk, string $path): string
    {
        return Storage::disk($disk)->url($path);
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }
}
