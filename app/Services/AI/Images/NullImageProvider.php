<?php

namespace App\Services\AI\Images;

use App\Contracts\ImageProvider;
use App\Enums\ImageCapability;
use App\Enums\ImageQualityTier;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Support\Facades\Log;

/**
 * Safe fallback ImageProvider (Step 16.1), bound whenever AI is disabled or the image vendor's key
 * is missing: renders a deterministic solid brand-tone PNG of the requested ratio so local dev,
 * preview mode and tests work end to end with no key and no vendor call.
 */
class NullImageProvider implements ImageProvider
{
    /**
     * Brand-tone fallbacks when the brief has no hex palette (cream, teal, lilac, lime).
     */
    private const FALLBACK_TONES = ['#F5F2E8', '#205557', '#C9A4D8', '#D2E842'];

    public function __construct(private readonly ImageNormalizer $normalizer = new ImageNormalizer) {}

    public function generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage
    {
        $hexes = array_values(array_filter(array_column($brief->palette, 'hex')));
        $tones = $hexes !== [] ? $hexes : self::FALLBACK_TONES;
        $tone = $tones[abs((int) ($options->seed ?? 0)) % count($tones)];

        return $this->placeholder($tone, $options);
    }

    public function edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage
    {
        return $this->placeholder(self::FALLBACK_TONES[abs((int) ($options->seed ?? 1)) % count(self::FALLBACK_TONES)], $options);
    }

    public function supports(ImageCapability $capability): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'null';
    }

    public function model(ImageQualityTier $tier): string
    {
        return 'placeholder';
    }

    public function costEstimate(ImageQualityTier $tier): float
    {
        return 0.0;
    }

    private function placeholder(string $hex, ImageRenderOptions $options): GeneratedImage
    {
        [$width, $height] = $options->aspectRatio->dimensions($options->tier === ImageQualityTier::Final ? 2048 : 1024);

        Log::info('Image request answered by NullImageProvider (no vendor call made)', [
            ...$options->logContext,
            'tier' => $options->tier->value,
            'aspect_ratio' => $options->aspectRatio->value,
            'ai_enabled' => (bool) config('ai.enabled', false),
            'provider_configured' => (string) config('ai.images.provider', 'null'),
        ]);

        return new GeneratedImage(
            mimeType: 'image/png',
            bytes: $this->normalizer->solidPng($width, $height, $hex),
            width: $width,
            height: $height,
            provider: $this->name(),
            model: $this->model($options->tier),
        );
    }
}
