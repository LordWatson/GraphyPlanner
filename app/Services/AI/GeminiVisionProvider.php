<?php

namespace App\Services\AI;

use App\Contracts\VisionProvider;
use App\Enums\Platform;
use App\Models\Client;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\ClientContextBuilder;
use App\Support\AI\VisionImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step B-6.2 — Google Gemini implementation of {@see VisionProvider}, over the same
 * `models/{model}:generateContent` REST surface the Image Studio uses, but asking for TEXT (strict
 * JSON) from an image part. The system instruction carries the brand's company context
 * ({@see ClientContextBuilder}) so captions sound like the brand; the user prompt names the target
 * platforms and the caption-length limits.
 *
 * Never throws: a non-2xx response, unparsable JSON, or any exception is logged and turned into
 * empty suggestions, so "Caption from photo" degrades to an empty box rather than an error. Logs
 * carry ids/model/timing only — never the image bytes, the prompt or the API key.
 */
class GeminiVisionProvider implements VisionProvider
{
    public function __construct(private readonly ClientContextBuilder $context = new ClientContextBuilder) {}

    /**
     * @param  array<int, string>  $platforms
     */
    public function captionsFromImage(Client $client, VisionImage $image, array $platforms): AiCaptionSuggestions
    {
        if (! $image->isSupported()) {
            Log::warning('Vision captions skipped: unsupported media type', [
                'org_id' => $client->org_id, 'client_id' => $client->id, 'mime_type' => $image->mimeType,
            ]);

            return new AiCaptionSuggestions;
        }

        $model = (string) config('ai.vision.model', 'gemini-2.5-flash');
        $logContext = ['org_id' => $client->org_id, 'client_id' => $client->id, 'provider' => 'gemini', 'model' => $model];

        Log::info('Vision caption request started', $logContext);

        $startedAt = microtime(true);

        try {
            $response = Http::baseUrl((string) config('ai.vision.base_url', 'https://generativelanguage.googleapis.com/v1beta'))
                ->withHeaders(['x-goog-api-key' => (string) config('ai.vision.api_key', '')])
                ->acceptJson()
                ->timeout((int) config('ai.vision.timeout', 45))
                ->post("/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $this->systemPrompt($client)]]],
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [
                            ['inlineData' => ['mimeType' => $image->mimeType, 'data' => base64_encode($image->bytes)]],
                            ['text' => $this->userPrompt($platforms)],
                        ],
                    ]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.7],
                ]);
        } catch (Throwable $e) {
            Log::error('Vision caption request threw an exception', [...$logContext, 'exception' => $e::class, 'duration_ms' => $this->elapsedMs($startedAt)]);

            return new AiCaptionSuggestions;
        }

        if (! $response->successful()) {
            Log::warning('Vision caption request rejected', [
                ...$logContext,
                'status' => $response->status(),
                'vendor_status' => $response->json('error.status'),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);

            return new AiCaptionSuggestions;
        }

        return $this->parse($response->json('candidates.0.content.parts.0.text'), $logContext, $startedAt);
    }

    /**
     * @param  array<string, mixed>  $logContext
     */
    private function parse(mixed $text, array $logContext, float $startedAt): AiCaptionSuggestions
    {
        if (! is_string($text) || trim($text) === '') {
            Log::warning('Vision caption response was empty', [...$logContext, 'duration_ms' => $this->elapsedMs($startedAt)]);

            return new AiCaptionSuggestions;
        }

        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('Vision caption response was not valid JSON', [...$logContext, 'duration_ms' => $this->elapsedMs($startedAt)]);

            return new AiCaptionSuggestions;
        }

        $suggestions = collect((array) (is_array($decoded) ? ($decoded['suggestions'] ?? []) : []))
            ->filter(fn ($item) => is_array($item) && is_string($item['caption'] ?? null) && trim($item['caption']) !== '')
            ->map(fn (array $item): AiCaptionSuggestion => new AiCaptionSuggestion(
                caption: trim((string) $item['caption']),
                hashtags: array_values(array_map('strval', is_array($item['hashtags'] ?? null) ? $item['hashtags'] : [])),
                altText: isset($item['altText']) && is_string($item['altText']) ? $item['altText'] : null,
                firstComment: isset($item['firstComment']) && is_string($item['firstComment']) ? $item['firstComment'] : null,
            ))
            ->take(3)
            ->values()
            ->all();

        Log::info('Vision caption request completed', [...$logContext, 'duration_ms' => $this->elapsedMs($startedAt), 'suggestions' => count($suggestions)]);

        return new AiCaptionSuggestions($suggestions);
    }

    private function systemPrompt(Client $client): string
    {
        return implode("\n", [
            'You are a social media copywriter for this brand. You are shown one photo and write caption options for it.',
            'Ground every caption in the company context below: the Brand Brain voice and tone, the audience, and the offer. Never describe anything not plausibly in or implied by the photo.',
            'Follow the hashtag policy: include every "Always use" hashtag and never a "Never use" one.',
            '',
            $this->context->build($client),
        ]);
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function userPrompt(array $platforms): string
    {
        $platforms = array_values(array_filter($platforms));

        $lines = [
            'Look at the photo and write 3 distinct caption options in the brand voice, each ready to post.',
            'For each, suggest 3-8 relevant hashtags (as bare words, no "#") and short alt text describing the image for accessibility.',
        ];

        if ($platforms !== []) {
            $lines[] = 'These will be posted on: '.implode(', ', $platforms).'. Keep captions within each platform\'s limits.';
        }

        $lines[] = 'Platform caption limits (characters): '.collect(Platform::cases())
            ->map(fn (Platform $platform) => $platform->label().'='.$platform->captionLimit())
            ->implode(', ');
        $lines[] = '';
        $lines[] = 'Respond with JSON: {"suggestions": [{"caption": string, "hashtags": string[], "altText": string|null, "firstComment": string|null}]}';

        return implode("\n", $lines);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
