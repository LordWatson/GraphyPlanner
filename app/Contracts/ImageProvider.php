<?php

namespace App\Contracts;

use App\Enums\ImageCapability;
use App\Enums\ImageQualityTier;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;

/**
 * Contract for AI image generation (Step 16.1), kept swappable the same way App\Contracts\AiProvider
 * is: Gemini first (Step 16.6), a GPT Image adapter for text-heavy designs (Step 16.12), and a
 * Null provider for local dev/tests. Implementations throw an
 * App\Exceptions\AI\ImageProviderException subclass on failure rather than returning a sentinel,
 * so the orchestrator can map each failure to one friendly message.
 */
interface ImageProvider
{
    /**
     * Render one image from a structured brief.
     */
    public function generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage;

    /**
     * Apply a short plain-words instruction ("warmer light", "remove the cup") to an existing image.
     */
    public function edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage;

    public function supports(ImageCapability $capability): bool;

    /**
     * Short provider name stored on generations and usage logs (e.g. "gemini").
     */
    public function name(): string;

    /**
     * The vendor model a given tier renders with.
     */
    public function model(ImageQualityTier $tier): string;

    /**
     * Estimated USD cost of one image at the given tier, for usage logs and Settings.
     */
    public function costEstimate(ImageQualityTier $tier): float;
}
