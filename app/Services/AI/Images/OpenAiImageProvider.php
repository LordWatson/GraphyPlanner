<?php

namespace App\Services\AI\Images;

use App\Contracts\ImageProvider;
use App\Enums\ImageAspectRatio;
use App\Enums\ImageCapability;
use App\Enums\ImageQualityTier;
use App\Enums\ReferenceImageRole;
use App\Exceptions\AI\ImageProviderException;
use App\Exceptions\AI\ImageProviderTimeoutException;
use App\Exceptions\AI\ImageProviderUnavailableException;
use App\Exceptions\AI\ImageSafetyBlockedException;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImagePromptRenderer;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * OpenAI GPT Image implementation of App\Contracts\ImageProvider (Step 16.12, stretch): only
 * routed to for briefs with a headline (`textOverlay`) when `ai.images.text_provider = openai`
 * and OPENAI_API_KEY is set (see ImageProviderRouter), because it renders text most reliably.
 * Same error mapping, retry and logging rules as GeminiImageProvider: never the prompt or key.
 */
class OpenAiImageProvider implements ImageProvider
{
    private const MAX_RETRIES = 2;

    public function __construct(private readonly ImagePromptRenderer $prompts = new ImagePromptRenderer) {}

    public function generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage
    {
        $prompt = $this->prompts->render($brief, $options->references);

        // Reference images can only be sent through the edits endpoint.
        if ($options->references !== []) {
            return $this->send('generate', $options, fn (PendingRequest $http) => $this->multipart($http, $options->references)
                ->post('/images/edits', $this->fields($prompt, $options)));
        }

        return $this->send('generate', $options, fn (PendingRequest $http) => $http
            ->post('/images/generations', $this->fields($prompt, $options)));
    }

    public function edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage
    {
        $reference = $source instanceof ReferenceImage
            ? $source
            : new ReferenceImage($source->mimeType, $source->bytes, ReferenceImageRole::Draft);

        return $this->send('edit', $options, fn (PendingRequest $http) => $this->multipart($http, [$reference])
            ->post('/images/edits', $this->fields($this->prompts->renderEdit($instruction), $options)));
    }

    public function supports(ImageCapability $capability): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'openai';
    }

    public function model(ImageQualityTier $tier): string
    {
        return (string) config('ai.images.openai.model', 'gpt-image-2');
    }

    public function costEstimate(ImageQualityTier $tier): float
    {
        return $tier === ImageQualityTier::Final
            ? (float) config('ai.images.openai.final_cost', 0.17)
            : (float) config('ai.images.openai.draft_cost', 0.02);
    }

    /**
     * The closest GPT Image canvas for each studio format.
     */
    public function size(ImageAspectRatio $ratio): string
    {
        return match ($ratio) {
            ImageAspectRatio::Square_1_1 => '1024x1024',
            ImageAspectRatio::Portrait_4_5, ImageAspectRatio::Story_9_16 => '1024x1536',
            ImageAspectRatio::Landscape_16_9, ImageAspectRatio::Link_1_91_1 => '1536x1024',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(string $prompt, ImageRenderOptions $options): array
    {
        return [
            'model' => $this->model($options->tier),
            'prompt' => $prompt,
            'size' => $this->size($options->aspectRatio),
            'quality' => $options->tier === ImageQualityTier::Final
                ? (string) config('ai.images.openai.final_quality', 'high')
                : (string) config('ai.images.openai.draft_quality', 'low'),
            'n' => 1,
        ];
    }

    /**
     * @param  array<int, ReferenceImage>  $references
     */
    private function multipart(PendingRequest $http, array $references): PendingRequest
    {
        foreach (array_values($references) as $index => $reference) {
            $extension = $reference->mimeType === 'image/jpeg' ? 'jpg' : ($reference->mimeType === 'image/webp' ? 'webp' : 'png');
            $http = $http->attach('image[]', $reference->bytes, "reference-{$index}.{$extension}", ['Content-Type' => $reference->mimeType]);
        }

        return $http->asMultipart();
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(string $method, ImageRenderOptions $options, Closure $call): GeneratedImage
    {
        $model = $this->model($options->tier);
        $context = [
            ...$options->logContext,
            'provider' => $this->name(),
            'method' => $method,
            'model' => $model,
            'tier' => $options->tier->value,
            'aspect_ratio' => $options->aspectRatio->value,
            'reference_count' => count($options->references),
        ];

        Log::info('OpenAI image request started', $context);
        $startedAt = microtime(true);

        for ($attempt = 1; ; $attempt++) {
            $http = Http::baseUrl((string) config('ai.images.openai.base_url'))
                ->withToken((string) config('ai.images.openai.api_key', ''))
                ->acceptJson()
                ->timeout((int) config('ai.images.timeout', 90));

            try {
                $response = $call($http);
            } catch (ConnectionException $e) {
                Log::error('OpenAI image request failed', [...$context, 'attempt' => $attempt, 'reason' => 'connection error', 'duration_ms' => $this->elapsedMs($startedAt)]);

                throw str_contains(strtolower($e->getMessage()), 'timed out')
                    ? new ImageProviderTimeoutException("OpenAI image request timed out ({$model})")
                    : new ImageProviderUnavailableException("OpenAI image request could not connect ({$model})");
            }

            if (($response->status() === 429 || $response->serverError()) && $attempt <= self::MAX_RETRIES) {
                $delayMs = 500 * (2 ** ($attempt - 1)) + random_int(0, 250);
                Log::warning('OpenAI image request retrying', [...$context, 'attempt' => $attempt, 'reason' => 'http '.$response->status(), 'delay_ms' => $delayMs]);
                Sleep::usleep($delayMs * 1000);

                continue;
            }

            return $this->parse($response, $options, $model, $context, $startedAt, $attempt);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function parse(Response $response, ImageRenderOptions $options, string $model, array $context, float $startedAt, int $attempt): GeneratedImage
    {
        $failure = [...$context, 'attempt' => $attempt, 'duration_ms' => $this->elapsedMs($startedAt), 'vendor_code' => $response->json('error.code')];

        if ($response->json('error.code') === 'moderation_blocked' || $response->json('error.type') === 'image_generation_user_error') {
            Log::warning('OpenAI image request blocked on safety grounds', $failure);

            throw new ImageSafetyBlockedException("OpenAI blocked the image request ({$model})");
        }

        if (! $response->successful()) {
            Log::error('OpenAI image request failed', [...$failure, 'reason' => 'http '.$response->status()]);

            throw $response->status() === 429 || $response->serverError()
                ? new ImageProviderUnavailableException("OpenAI image request failed with HTTP {$response->status()}")
                : new ImageProviderException("OpenAI rejected the image request with HTTP {$response->status()}");
        }

        $encoded = $response->json('data.0.b64_json');
        $bytes = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($bytes === false || $bytes === '') {
            Log::error('OpenAI image request failed', [...$failure, 'reason' => 'no image in response']);

            throw new ImageProviderUnavailableException('OpenAI returned no image');
        }

        $size = @getimagesizefromstring($bytes);
        $cost = $this->costEstimate($options->tier);

        Log::info('OpenAI image request completed', [...$context, 'attempt' => $attempt, 'duration_ms' => $this->elapsedMs($startedAt), 'cost_estimate' => $cost]);

        return new GeneratedImage(
            mimeType: $size !== false ? (string) $size['mime'] : 'image/png',
            bytes: $bytes,
            width: $size !== false ? (int) $size[0] : 0,
            height: $size !== false ? (int) $size[1] : 0,
            provider: $this->name(),
            model: $model,
            providerRequestId: $response->header('x-request-id') ?: null,
            costEstimate: $cost,
        );
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
