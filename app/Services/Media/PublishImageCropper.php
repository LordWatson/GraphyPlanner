<?php

namespace App\Services\Media;

use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Post;
use App\Support\Media\CropBox;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Applies a post's per-channel image format (`Post::$media_format`) and each image's framing
 * (`post_assets.crop_*`) to the bytes a publish adapter is about to send. GD only, like
 * `ImageNormalizer`. Never fails a publish: anything it can't read or encode goes out as the
 * original, with a warning logged.
 *
 * The rules mirror the editor preview (`resources/js/lib/media-format.ts`):
 * - a fixed format crops every image to that ratio around its focus point;
 * - `Original` leaves images alone, except on Instagram, whose API rejects anything outside
 *   4:5–1.91:1 and frames a carousel at its first image's shape — so there every image is cut to
 *   the first image's ratio, clamped into that range.
 */
class PublishImageCropper
{
    /** JPEG/WebP quality for re-encoded crops — high, since platforms recompress again. */
    public const QUALITY = 92;

    /** Below this difference in width ÷ height an image already has the target shape. */
    private const RATIO_TOLERANCE = 0.01;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::gd();
    }

    /**
     * The width ÷ height every image is cut to on this channel, or null to send them untouched.
     *
     * @param  array{0: int, 1: int}|null  $firstImageSize  the first image's pixel size, when known
     */
    public function targetRatio(Post $post, Platform $platform, ?array $firstImageSize): ?float
    {
        $ratio = $post->mediaRatioFor($platform);

        if ($ratio->quotient() !== null) {
            return $ratio->quotient();
        }

        if ($platform !== Platform::Instagram || $firstImageSize === null || $firstImageSize[1] <= 0) {
            return null;
        }

        $aspect = $firstImageSize[0] / $firstImageSize[1];

        return min(MediaRatio::INSTAGRAM_MAX, max(MediaRatio::INSTAGRAM_MIN, $aspect));
    }

    /**
     * Pixel size of encoded image bytes, or null when they can't be read.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $bytes): ?array
    {
        try {
            $image = $this->manager->read($bytes);

            return [$image->width(), $image->height()];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Crop one attached image to `$ratio` around its saved focus point. Returns the re-encoded
     * bytes (same format as the original), or null when the original should be sent as-is —
     * it already has that shape, is an animated GIF, or couldn't be processed.
     */
    public function crop(Asset $asset, string $bytes, float $ratio, ?Post $post = null): ?string
    {
        if ($asset->mime_type === 'image/gif') {
            return null;
        }

        [$focusX, $focusY, $zoom] = self::framing($asset);

        try {
            $image = $this->manager->read($bytes);
            $width = $image->width();
            $height = $image->height();

            if ($zoom <= CropBox::MIN_ZOOM && abs($width / max(1, $height) - $ratio) < self::RATIO_TOLERANCE) {
                return null;
            }

            $box = CropBox::compute($width, $height, $ratio, $focusX, $focusY, $zoom);

            if ($box->coversWhole($width, $height)) {
                return null;
            }

            $image->crop($box->width, $box->height, $box->x, $box->y);
            $encoded = (string) $image->encode(new AutoEncoder(quality: self::QUALITY));

            Log::info('Cropped image for publishing', [
                'post_id' => $post?->id,
                'asset_id' => $asset->id,
                'ratio' => round($ratio, 4),
                'from' => "{$width}x{$height}",
                'to' => "{$box->width}x{$box->height}",
            ]);

            return $encoded;
        } catch (Throwable $e) {
            Log::warning('Image crop failed; publishing the original', [
                'post_id' => $post?->id,
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * An attached asset's focus point and zoom from the `post_assets` pivot (centre, 1× when the
     * asset wasn't loaded through `Post::assets()`).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function framing(Asset $asset): array
    {
        /** @var object{crop_x?: mixed, crop_y?: mixed, crop_zoom?: mixed}|null $pivot */
        $pivot = $asset->getRelationValue('pivot');

        return [
            (float) ($pivot->crop_x ?? 0.5),
            (float) ($pivot->crop_y ?? 0.5),
            (float) ($pivot->crop_zoom ?? 1.0),
        ];
    }
}
