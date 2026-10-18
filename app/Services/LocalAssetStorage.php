<?php

namespace App\Services;

use App\Contracts\AssetStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores Asset uploads (and invoice/persona PDFs, AI images) on a Laravel filesystem disk —
 * `config('assets.disk')`. Bound to `App\Contracts\AssetStorage` in `AppServiceProvider`.
 *
 * Step I-1.6: the default disk is the private `local` disk, and `url()` only ever hands out a
 * signed, expiring `temporaryUrl()` for a disk that isn't explicitly public, so a stored file is
 * never reachable at a permanent, guessable address. Rows created on the old `public` disk keep
 * their plain public URL. The same code serves an `s3` disk once it's configured.
 */
class LocalAssetStorage implements AssetStorage
{
    /**
     * @return array{disk: string, path: string, url: string, mime_type: string|null, size: int}
     */
    public function store(UploadedFile $file, string $directory): array
    {
        $disk = $this->disk();

        $path = (string) $file->store($directory, $disk);

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => $this->url($disk, $path),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
        ];
    }

    /**
     * @return array{disk: string, path: string, url: string, mime_type: string|null, size: int}
     */
    public function storeContents(string $contents, string $mimeType, string $directory, string $extension): array
    {
        $disk = $this->disk();
        $path = trim($directory, '/').'/'.Str::uuid().'.'.ltrim($extension, '.');

        Storage::disk($disk)->put($path, $contents);

        return [
            'disk' => $disk,
            'path' => $path,
            'url' => $this->url($disk, $path),
            'mime_type' => $mimeType,
            'size' => strlen($contents),
        ];
    }

    public function contents(string $disk, string $path): ?string
    {
        return Storage::disk($disk)->exists($path) ? Storage::disk($disk)->get($path) : null;
    }

    /**
     * A plain URL for a public disk; a signed URL valid for `assets.url_ttl_minutes` otherwise.
     */
    public function url(string $disk, string $path): string
    {
        return $this->signedUrl($disk, $path, (int) config('assets.url_ttl_minutes', 60));
    }

    /**
     * Same as `url()`, with a caller-chosen lifetime (e.g. long enough for a vendor to fetch it).
     */
    public function signedUrl(string $disk, string $path, int $minutes): string
    {
        if (self::isPublicDisk($disk)) {
            return Storage::disk($disk)->url($path);
        }

        return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(max(1, $minutes)));
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    public static function isPublicDisk(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.visibility") === 'public';
    }

    private function disk(): string
    {
        return (string) config('assets.disk', 'local');
    }
}
