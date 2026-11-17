<?php

namespace App\Actions\Stories;

use App\Contracts\AssetStorage;
use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\StoryHandoff;
use App\Services\Media\PublishImageCropper;
use App\Support\Stories\StoryHandoffMedia;
use Illuminate\Support\Facades\Log;

/**
 * Step S-4.2 — exactly the file Instagram should receive for an assisted Story.
 *
 * It is the item the publish adapter would have sent (`Post::publishableMedia()`: the video, else
 * the first image — a Story is one item), and an image is cut the way the adapter cuts it: to the
 * Story format's 9:16 around the saved focus point, through the same `PublishImageCropper`. What
 * the client approved in the preview is therefore what the poster shares. A video goes out as
 * uploaded, as it does in the auto lane.
 *
 * Read through `AssetStorage`, so a remote disk works the same as a local one, and served from our
 * own token-gated route rather than a signed storage URL: the share sheet needs a same-origin file
 * with a correct `Content-Type`, and a long-lived storage link has no business sitting in a page.
 */
class GetStoryHandoffMediaAction
{
    public function __construct(
        private readonly AssetStorage $storage,
        private readonly PublishImageCropper $cropper = new PublishImageCropper,
    ) {}

    public function __invoke(StoryHandoff $handoff): ?StoryHandoffMedia
    {
        $post = $handoff->post;
        $target = $handoff->target;
        $account = $target?->socialAccount;

        if ($post === null || $target === null || $account === null) {
            return null;
        }

        /** @var Asset|null $asset */
        $asset = $post->publishableMedia()->first();

        if ($asset === null || blank($asset->disk) || blank($asset->path) || ! self::isServable($asset)) {
            return null;
        }

        $disk = (string) $asset->disk;
        $path = (string) $asset->path;

        if ($asset->type === AssetType::Video) {
            $stream = $this->storage->readStream($disk, $path);

            return $stream === null
                ? null
                : new StoryHandoffMedia(self::mimeFor($asset), self::filenameFor($asset), stream: $stream, size: $asset->size);
        }

        $bytes = $this->storage->contents($disk, $path);

        if ($bytes === null) {
            Log::warning('Story handoff media is missing from storage', [
                'post_id' => $post->id,
                'asset_id' => $asset->id,
                'story_handoff_id' => $handoff->id,
            ]);

            return null;
        }

        $target->setRelation('post', $post);

        // Read the size from the header, not by decoding: this is an unauthenticated request, and
        // an image too large to decode comfortably is served as it is rather than cropped.
        $size = @getimagesizefromstring($bytes);
        $dimensions = is_array($size) ? [(int) $size[0], (int) $size[1]] : null;

        if ($dimensions !== null && $dimensions[0] * $dimensions[1] <= self::MAX_CROP_PIXELS) {
            $ratio = $this->cropper->targetRatio($post, $account->platform, $dimensions, $target->resolvedFormat());

            if ($ratio !== null) {
                $bytes = $this->cropper->crop($asset, $bytes, $ratio, $post) ?? $bytes;
            }
        }

        return new StoryHandoffMedia(self::mimeFor($asset), self::filenameFor($asset), bytes: $bytes, size: strlen($bytes));
    }

    /**
     * The only types this route will ever put on the wire, inline, on the app's own origin. An
     * asset's `mime_type` is stored data (an imported design keeps what its provider declared),
     * so anything that could run — SVG, HTML — is refused here rather than trusted to the CSP.
     */
    public const SERVABLE_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'video/mp4', 'video/quicktime', 'video/webm',
    ];

    /** Above this many pixels an image is served uncropped rather than decoded in a web request. */
    public const MAX_CROP_PIXELS = 50_000_000;

    public static function isServable(Asset $asset): bool
    {
        return in_array(self::mimeFor($asset), self::SERVABLE_TYPES, true);
    }

    public static function mimeFor(Asset $asset): string
    {
        if (filled($asset->mime_type)) {
            return mb_strtolower(trim((string) $asset->mime_type));
        }

        return $asset->type === AssetType::Video ? 'video/mp4' : 'image/jpeg';
    }

    /**
     * A neutral name: the share sheet shows it, and the original filename is the agency's own.
     */
    public static function filenameFor(Asset $asset): string
    {
        $extension = match (self::mimeFor($asset)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            default => $asset->type === AssetType::Video ? 'mp4' : 'jpg',
        };

        return 'alima-story.'.$extension;
    }
}
