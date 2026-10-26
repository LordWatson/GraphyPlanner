<?php

namespace App\Support\Mcp;

use App\Contracts\AssetStorage;
use App\Enums\AssetType;
use App\Models\Asset;

/**
 * MCP media — produces a small, bounded image preview (raw bytes) of an Asset so an MCP tool can hand
 * it to the assistant as a vision block (`Response::image()`), letting it actually *see* the media the
 * user uploaded and write captions from it.
 *
 * It is a deliberate, scoped exception to "no media leaves the server": the bytes are the user's own
 * just-uploaded media, delivered to the user's own AI client, as a downscaled preview — never a signed
 * URL or storage path. Best-effort: any failure returns null and the caller falls back to metadata.
 *
 * Reuses the same storage + GD stack as `App\Jobs\ProbeAssetJob`.
 */
class McpImagePreview
{
    /** Longest edge of the preview handed to the model — enough for vision, small on the wire. */
    private const MAX_EDGE = 768;

    /**
     * Preview bytes + mime for an asset the model can look at, or null when there's nothing viewable
     * (e.g. a video with no thumbnail, a missing file, or GD can't read it).
     *
     * @return array{data: string, mime: string}|null
     */
    public static function for(Asset $asset): ?array
    {
        $bytes = self::sourceBytes($asset);

        if ($bytes === null) {
            return null;
        }

        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest > self::MAX_EDGE) {
            $scaled = imagescale($image, (int) round($width * self::MAX_EDGE / $longest));

            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        ob_start();
        $ok = imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        if (! $ok || $jpeg === '') {
            return null;
        }

        return ['data' => $jpeg, 'mime' => 'image/jpeg'];
    }

    /**
     * The best source bytes to preview: the 480px thumbnail when it's ready (cheap, and covers videos),
     * else the original for an image (also covers the race where ProbeAssetJob hasn't run yet).
     */
    private static function sourceBytes(Asset $asset): ?string
    {
        $storage = app(AssetStorage::class);

        if (filled($asset->thumbnail_path) && filled($asset->disk)) {
            $bytes = $storage->contents((string) $asset->disk, (string) $asset->thumbnail_path);

            if ($bytes !== null) {
                return $bytes;
            }
        }

        if ($asset->type === AssetType::Image && filled($asset->disk) && filled($asset->path)) {
            return $storage->contents((string) $asset->disk, (string) $asset->path);
        }

        return null;
    }
}
