<?php

namespace App\Services\AI\Images;

use App\Contracts\ImageProvider;
use App\Enums\ImageCapability;
use App\Enums\ImageQualityTier;
use App\Exceptions\AI\ImageProviderException;
use App\Exceptions\AI\ImageProviderTimeoutException;
use App\Exceptions\AI\ImageProviderUnavailableException;
use App\Exceptions\AI\ImageSafetyBlockedException;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\GeneratedImage;
use App\Support\AI\Images\ImagePromptRenderer;
use App\Support\AI\Images\ImageRenderOptions;
use App\Support\AI\Images\ReferenceImage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Google Gemini implementation of App\Contracts\ImageProvider (Step 16.6), over the
 * `models/{model}:generateContent` REST endpoint. Draft tier → Nano Banana 2 (Gemini 3.1 Flash
 * Image, 1K); final tier → Nano Banana Pro (Gemini 3 Pro Image, 2K). Reference images go in as
 * inline image parts ahead of one ordered prompt (ImagePromptRenderer) that labels each by role.
 *
 * 429/5xx are retried with exponential backoff + jitter (max 2 retries), then mapped to typed
 * exceptions. Logs carry ids, model, tier, timings and cost only: never the prompt, the brief,
 * image bytes, vendor error text or the API key. Outputs carry Google's SynthID watermark.
 */
class GeminiImageProvider implements ImageProvider
{
    private const MAX_RETRIES = 2;

    /**
     * finishReason / blockReason values that mean "refused on safety/policy grounds".
     */
    private const SAFETY_REASONS = [
        'SAFETY', 'IMAGE_SAFETY', 'PROHIBITED_CONTENT', 'IMAGE_PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII',
        'RECITATION', 'IMAGE_RECITATION', 'OTHER_SAFETY',
    ];

    public function __construct(private readonly ImagePromptRenderer $prompts = new ImagePromptRenderer) {}

    public function generate(AiImageBrief $brief, ImageRenderOptions $options): GeneratedImage
    {
        return $this->request(
            parts: [
                ...$this->imageParts($options->references),
                ['text' => $this->prompts->render($brief, $options->references)],
            ],
            options: $options,
            method: 'generate',
        );
    }

    public function edit(GeneratedImage|ReferenceImage $source, string $instruction, ImageRenderOptions $options): GeneratedImage
    {
        return $this->request(
            parts: [
                ['inlineData' => ['mimeType' => $source->mimeType, 'data' => base64_encode($source->bytes)]],
                ['text' => $this->prompts->renderEdit($instruction)],
            ],
            options: $options,
            method: 'edit',
        );
    }

    public function supports(ImageCapability $capability): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function model(ImageQualityTier $tier): string
    {
        return $tier === ImageQualityTier::Final
            ? (string) config('ai.images.gemini.final_model', 'gemini-3-pro-image')
            : (string) config('ai.images.gemini.draft_model', 'gemini-3.1-flash-image');
    }

    public function costEstimate(ImageQualityTier $tier): float
    {
        return $tier === ImageQualityTier::Final
            ? (float) config('ai.images.gemini.final_cost', 0.134)
            : (float) config('ai.images.gemini.draft_cost', 0.067);
    }

    /**
     * @param  array<int, ReferenceImage>  $references
     * @return array<int, array<string, mixed>>
     */
    private function imageParts(array $references): array
    {
        return array_values(array_map(fn (ReferenceImage $reference): array => [
            'inlineData' => ['mimeType' => $reference->mimeType, 'data' => base64_encode($reference->bytes)],
        ], $references));
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     */
    private function request(array $parts, ImageRenderOptions $options, string $method): GeneratedImage
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

        $generationConfig = [
            'responseModalities' => ['IMAGE'],
            'imageConfig' => [
                'aspectRatio' => $options->aspectRatio->providerRatio(),
                'imageSize' => $options->tier === ImageQualityTier::Final
                    ? (string) config('ai.images.gemini.final_size', '2K')
                    : (string) config('ai.images.gemini.draft_size', '1K'),
            ],
        ];

        if ($options->seed !== null) {
            $generationConfig['seed'] = $options->seed;
        }

        $payload = [
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => $generationConfig,
        ];

        Log::info('Gemini image request started', $context);

        $startedAt = microtime(true);

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::baseUrl((string) config('ai.images.gemini.base_url'))
                    ->withHeaders(['x-goog-api-key' => (string) config('ai.images.gemini.api_key', '')])
                    ->acceptJson()
                    ->timeout((int) config('ai.images.timeout', 90))
                    ->post("/models/{$model}:generateContent", $payload);
            } catch (ConnectionException $e) {
                $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');

                if (! $timedOut && $attempt <= self::MAX_RETRIES) {
                    $this->backoff($attempt, $context, 'connection error');

                    continue;
                }

                $this->logFailure($context, $startedAt, $attempt, $timedOut ? 'timeout' : 'connection error');

                throw $timedOut
                    ? new ImageProviderTimeoutException("Gemini image request timed out ({$model})")
                    : new ImageProviderUnavailableException("Gemini image request could not connect ({$model})");
            }

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt <= self::MAX_RETRIES) {
                    $this->backoff($attempt, $context, 'http '.$response->status());

                    continue;
                }

                $this->logFailure($context, $startedAt, $attempt, 'http '.$response->status(), $response);

                throw new ImageProviderUnavailableException("Gemini image request failed with HTTP {$response->status()} ({$model})");
            }

            return $this->parse($response, $options, $model, $context, $startedAt, $attempt);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function parse(Response $response, ImageRenderOptions $options, string $model, array $context, float $startedAt, int $attempt): GeneratedImage
    {
        if (! $response->successful()) {
            $this->logFailure($context, $startedAt, $attempt, 'http '.$response->status(), $response);

            throw new ImageProviderException("Gemini rejected the image request with HTTP {$response->status()} ({$model})");
        }

        $blockReason = $response->json('promptFeedback.blockReason');
        $finishReason = $response->json('candidates.0.finishReason');

        foreach ((array) $response->json('candidates.0.content.parts', []) as $part) {
            $data = is_array($part) ? ($part['inlineData'] ?? $part['inline_data'] ?? null) : null;

            if (! is_array($data) || ! is_string($data['data'] ?? null)) {
                continue;
            }

            $bytes = base64_decode($data['data'], true);

            if ($bytes === false || $bytes === '') {
                continue;
            }

            $size = @getimagesizefromstring($bytes);
            $cost = $this->costEstimate($options->tier);

            Log::info('Gemini image request completed', [
                ...$context,
                'attempt' => $attempt,
                'duration_ms' => $this->elapsedMs($startedAt),
                'cost_estimate' => $cost,
                'provider_request_id' => $response->json('responseId'),
                'bytes' => strlen($bytes),
            ]);

            return new GeneratedImage(
                mimeType: (string) ($data['mimeType'] ?? $data['mime_type'] ?? 'image/png'),
                bytes: $bytes,
                width: $size !== false ? (int) $size[0] : 0,
                height: $size !== false ? (int) $size[1] : 0,
                provider: $this->name(),
                model: $model,
                providerRequestId: is_string($response->json('responseId')) ? $response->json('responseId') : null,
                costEstimate: $cost,
            );
        }

        $reason = is_string($blockReason) ? $blockReason : (is_string($finishReason) ? $finishReason : null);

        if ($reason !== null && in_array($reason, self::SAFETY_REASONS, true)) {
            Log::warning('Gemini image request blocked on safety grounds', [
                ...$context,
                'attempt' => $attempt,
                'reason' => $reason,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            throw new ImageSafetyBlockedException("Gemini blocked the image request ({$reason})");
        }

        $this->logFailure($context, $startedAt, $attempt, 'no image in response (finish_reason: '.($reason ?? 'none').')');

        throw new ImageProviderUnavailableException('Gemini returned no image ('.($reason ?? 'no finish reason').')');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function backoff(int $attempt, array $context, string $reason): void
    {
        $delayMs = 500 * (2 ** ($attempt - 1)) + random_int(0, 250);

        Log::warning('Gemini image request retrying', [...$context, 'attempt' => $attempt, 'reason' => $reason, 'delay_ms' => $delayMs]);

        Sleep::usleep($delayMs * 1000);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(array $context, float $startedAt, int $attempt, string $reason, ?Response $response = null): void
    {
        Log::error('Gemini image request failed', [
            ...$context,
            'attempt' => $attempt,
            'reason' => $reason,
            'duration_ms' => $this->elapsedMs($startedAt),
            // Google's status code name only (e.g. RESOURCE_EXHAUSTED): never the message text.
            'vendor_status' => $response?->json('error.status'),
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
