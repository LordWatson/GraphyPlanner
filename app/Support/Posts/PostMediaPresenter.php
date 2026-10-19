<?php

namespace App\Support\Posts;

use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Post;
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
