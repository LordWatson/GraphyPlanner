<?php

namespace App\Support\Posts;

use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Media\PublishImageCropper;

/**
 * The image-format payload every post preview reads (editor, review link, client portal): the
 * resolved format per channel and each attached image's size + framing, so `SocialPostPreview`
 * frames media exactly the way `PublishImageCropper` will cut it.
 */
final class PostMediaPresenter
{
    /**
     * The format in use on every channel, defaults filled in: `{"instagram": "original", ...}`.
     *
     * @return array<string, string>
     */
    public static function mediaFormat(Post $post): array
    {
        return collect(Platform::cases())
            ->mapWithKeys(fn (Platform $platform) => [$platform->value => $post->mediaRatioFor($platform)->value])
            ->all();
    }

    /**
     * The formats each channel offers, for the editor's picker.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function formatOptions(): array
    {
        return collect(Platform::cases())
            ->mapWithKeys(fn (Platform $platform) => [
                $platform->value => array_map(
                    fn (MediaRatio $ratio) => ['value' => $ratio->value, 'label' => $ratio->label()],
                    MediaRatio::optionsFor($platform),
                ),
            ])
            ->all();
    }

    /**
     * An attached asset as every post preview reads it (editor, review link, portal): its URLs
     * (Step M-2.4: `thumbnail_url` for lists and grids), type, probe data, framing and alt text.
     * Load it through `Post::assets()` so the pivot is present.
     *
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        /** @var object{alt_text?: string|null}|null $pivot */
        $pivot = $asset->getRelationValue('pivot');

        return [
            'id' => $asset->id,
            'url' => $asset->url,
            'thumbnail_url' => $asset->thumbnailUrl(),
            'original_filename' => $asset->original_filename,
            'type' => $asset->type?->value,
            'is_ai_generated' => $asset->is_ai_generated,
            'size' => $asset->size,
            'duration_ms' => $asset->duration_ms,
            'alt_text' => $pivot->alt_text ?? null,
            ...self::framing($asset),
        ];
    }

    /**
     * Step I-3.1 / I-3.2 (M-2.2 / M-2.3): one target's channel-specific content — its overrides
     * (null = uses the master), the caption exactly as it publishes there, and its format
     * (explicit choice, and what it resolves to). The target's `post` relation must be set.
     *
     * @return array<string, mixed>
     */
    public static function channel(PostTarget $target): array
    {
        return [
            'caption_override' => $target->caption_override,
            'hashtags_override' => $target->hashtags_override,
            'first_comment' => $target->first_comment,
            'link_url' => $target->link_url,
            'format' => $target->format,
            'resolved_format' => $target->resolvedFormat()?->key,
            'published_caption' => $target->publishedCaption(),
            'first_comment_effective' => $target->effectiveFirstComment(),
            'is_customized' => $target->isCustomized(),
        ];
    }

    /**
     * What a client sees for one channel (review link, portal): the caption and first comment
     * exactly as they'll publish there, and the format — so they approve the real thing, never
     * the raw overrides. The target's `post` relation must be set.
     *
     * @return array{published_caption: string, first_comment: string|null, format: string|null}
     */
    public static function channelPreview(PostTarget $target): array
    {
        return [
            'published_caption' => $target->publishedCaption(),
            'first_comment' => $target->effectiveFirstComment(),
            'format' => $target->resolvedFormat()?->key,
        ];
    }

    /**
     * Size and framing fields merged into an attached asset's payload. Load the asset through
     * `Post::assets()` so the pivot's crop is present.
     *
     * @return array{width: int|null, height: int|null, crop: array{x: float, y: float, zoom: float}}
     */
    public static function framing(Asset $asset): array
    {
        [$x, $y, $zoom] = PublishImageCropper::framing($asset);

        return [
            'width' => $asset->width,
            'height' => $asset->height,
            'crop' => ['x' => $x, 'y' => $y, 'zoom' => $zoom],
        ];
    }
}
