<?php

namespace App\Support;

use App\Contracts\AssetStorage;
use App\Services\LocalAssetStorage;

/**
 * Step I-1.6 — the URL to hand out for a stored file. A file on a private disk gets a fresh signed,
 * expiring URL every time it's read (a URL saved at upload time would have expired); a file on a
 * public disk, or an external link with no disk at all, keeps the stored URL.
 */
final class StoredFileUrl
{
    public static function resolve(?string $disk, ?string $path, ?string $storedUrl): ?string
    {
        if (blank($disk) || blank($path) || LocalAssetStorage::isPublicDisk((string) $disk)) {
            return $storedUrl;
        }

        return app(AssetStorage::class)->url((string) $disk, (string) $path);
    }
}
