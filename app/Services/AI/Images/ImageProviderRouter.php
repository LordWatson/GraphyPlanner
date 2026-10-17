<?php

namespace App\Services\AI\Images;

use App\Contracts\ImageProvider;
use App\Support\AI\Images\AiImageBrief;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Picks the ImageProvider for one render (Step 16.12): briefs with a headline (`textOverlay`) go to
 * OpenAI GPT Image when `ai.images.text_provider = openai` is configured with a key (it renders text
 * best); everything else uses the default ImageProvider binding (Gemini, or the Null provider).
 */
class ImageProviderRouter
{
    public function __construct(
        private readonly ImageProvider $default,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, int|string|null>  $logContext
     */
    public function for(AiImageBrief $brief, array $logContext = []): ImageProvider
    {
        $provider = $this->select($brief->textOverlay !== null);

        Log::info('Image provider routed', [
            ...$logContext,
            'provider' => $provider->name(),
            'reason' => $provider === $this->default ? 'default provider' : 'headline text: text_provider',
        ]);

        return $provider;
    }

    /**
     * The routing rule on its own, for callers that only know whether a headline is wanted
     * (GenerateImageSetAction records the provider before the brief exists).
     */
    public function select(bool $hasHeadline): ImageProvider
    {
        $useTextProvider = $hasHeadline
            && (bool) config('ai.enabled', false)
            && config('ai.images.text_provider') === 'openai'
            && (string) config('ai.images.openai.api_key', '') !== '';

        return $useTextProvider ? $this->container->make(OpenAiImageProvider::class) : $this->default;
    }
}
