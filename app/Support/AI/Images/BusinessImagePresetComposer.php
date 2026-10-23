<?php

namespace App\Support\AI\Images;

use App\Enums\BusinessImagePreset;
use App\Enums\ImageAspectRatio;
use App\Models\Client;
use Illuminate\Support\Str;

/**
 * Step B-6.5 — turns a chosen {@see BusinessImagePreset} plus one line of the person's text into a
 * validated {@see GenerateImageSetData} for the shared Image Studio engine (Phase 16). The text
 * becomes both the image's headline overlay (always on for a preset) and part of the subject; the
 * preset supplies the rest of the art direction. The variant count is clamped to the configured
 * maximum so a crafted request can never exceed the per-set ceiling.
 */
class BusinessImagePresetComposer
{
    /** The headline overlay is capped at the same 80 characters the Image Studio enforces. */
    private const HEADLINE_LIMIT = 80;

    public function compose(
        Client $brand,
        BusinessImagePreset $preset,
        string $text,
        ?ImageAspectRatio $aspectRatio,
        int $variantCount,
        string $requestId,
    ): GenerateImageSetData {
        $text = trim($text);
        $max = max(1, (int) config('ai.images.max_variants', 4));

        /** @var array{subject?: string, mood?: string, style?: string, composition?: string} $overrides */
        $overrides = array_filter(
            $preset->artDirection($brand->name, $text),
            fn (string $value): bool => trim($value) !== '',
        );

        return new GenerateImageSetData(
            requestId: $requestId,
            aspectRatio: $aspectRatio ?? $preset->defaultFormat(),
            variantCount: max(1, min($variantCount, $max)),
            overrides: $overrides,
            withHeadline: true,
            headline: Str::limit($text, self::HEADLINE_LIMIT, ''),
        );
    }
}
