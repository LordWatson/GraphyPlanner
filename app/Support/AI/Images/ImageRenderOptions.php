<?php

namespace App\Support\AI\Images;

use App\Enums\ImageAspectRatio;
use App\Enums\ImageQualityTier;

/**
 * How to render one image (Step 16.1): tier (draft/final model), format, an optional seed so a
 * set's variants show real range, the reference images to ground it in, and the ids a provider
 * should put in its log lines (`org_id`, `client_id`, `generation_id`, `variant_id`).
 */
final readonly class ImageRenderOptions
{
    /**
     * @param  array<int, ReferenceImage>  $references
     * @param  array<string, int|string|null>  $logContext
     */
    public function __construct(
        public ImageQualityTier $tier = ImageQualityTier::Draft,
        public ImageAspectRatio $aspectRatio = ImageAspectRatio::Square_1_1,
        public ?int $seed = null,
        public array $references = [],
        public array $logContext = [],
    ) {}
}
