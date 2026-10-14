<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Enums\Platform;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DeepSeek-backed implementation of App\Contracts\AiProvider (Step 2.2). Builds a system
 * prompt from the client's brand brain (voice, pillars, do/don'ts, audience) plus platform
 * caption-length constraints, and asks DeepSeek's chat completions endpoint for strict JSON
 * matching each method's DTO shape. Not bound by default — provider selection (Step 2.7)
 * decides when this (vs. App\Services\AI\NullAiProvider) is actually resolved against the
 * AiProvider contract.
 */
class DeepSeekProvider implements AiProvider
{
    /**
     * Schema is invalid/unparsable this many times before giving up and logging a failure.
     */
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly AiGuardrailService $guardrail = new AiGuardrailService(),
    ) {}

    public function suggestCaptions(Post $post): AiCaptionSuggestions
    {
        $client = $post->client;

        $payload = $this->requestStructured(
            method: 'suggestCaptions',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildCaptionsUserPrompt($post),
            validator: fn (array $data): bool => $this->isValidCaptionSuggestionsShape($data),
            context: ['org_id' => $client?->org_id, 'client_id' => $client?->id, 'post_id' => $post->id],
        );

        if ($payload === null) {
            return new AiCaptionSuggestions();
        }

        $suggestions = collect($payload['suggestions'] ?? [])
            ->map(fn (array $item): AiCaptionSuggestion => new AiCaptionSuggestion(
                caption: (string) ($item['caption'] ?? ''),
                hashtags: array_values(array_map('strval', $item['hashtags'] ?? [])),
                altText: isset($item['altText']) ? (string) $item['altText'] : null,
                firstComment: isset($item['firstComment']) ? (string) $item['firstComment'] : null,
            ))
            ->values()
            ->all();

        return new AiCaptionSuggestions($suggestions);
    }

    public function recommendPosts(Client $client): AiPostIdeas
    {
        $payload = $this->requestStructured(
            method: 'recommendPosts',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildRecommendPostsUserPrompt($client),
            validator: fn (array $data): bool => $this->isValidPostIdeasShape($data),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id],
        );

        if ($payload === null) {
            return new AiPostIdeas();
        }

        $ideas = collect($payload['ideas'] ?? [])
            ->map(fn (array $item): AiPostIdea => new AiPostIdea(
                title: (string) ($item['title'] ?? ''),
                description: (string) ($item['description'] ?? ''),
                platforms: array_values(array_map('strval', $item['platforms'] ?? [])),
                suggestedCaption: isset($item['suggestedCaption']) ? (string) $item['suggestedCaption'] : null,
                suggestedPostAt: isset($item['suggestedPostAt']) ? (string) $item['suggestedPostAt'] : null,
            ))
            ->values()
            ->all();

        return new AiPostIdeas($ideas);
    }

    public function discoverTrends(Client $client, array $platforms): AiTrends
    {
        $payload = $this->requestStructured(
            method: 'discoverTrends',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildDiscoverTrendsUserPrompt($client, $platforms),
            validator: fn (array $data): bool => $this->isValidTrendsShape($data),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id, 'platforms' => $platforms],
        );

        if ($payload === null) {
            return new AiTrends();
        }

        $trends = collect($payload['trends'] ?? [])
            ->map(fn (array $item): AiTrend => new AiTrend(
                title: (string) ($item['title'] ?? ''),
                summary: (string) ($item['summary'] ?? ''),
                platforms: array_values(array_map('strval', $item['platforms'] ?? [])),
                confidence: (float) ($item['confidence'] ?? 0.0),
            ))
            ->values()
            ->all();

        return new AiTrends($trends);
    }

    /**
     * Build the shared system prompt: brand voice/pillars/do-not-says/audience from the brand
     * brain (reusing BrandBrain::toGrokContext()'s markdown briefing), plus the client's
     * industry and platform caption-length constraints so every method stays on-brand and
     * platform-aware.
     */
    private function buildSystemPrompt(?Client $client): string
    {
        $lines = [
            'You are an expert social media strategist embedded in Graphy, a content planning tool.',
            'You never auto-post; you only propose ideas, captions, hashtags, alt text, and posting times for a human to review.',
            'Always respond with a single, strictly valid JSON object matching the schema given in the user message — no prose, no markdown fences.',
        ];

        if ($client !== null) {
            $lines[] = '';
            $lines[] = 'Client industry: '.($client->industry ?? 'Unknown');

            $brandBrain = $client->brandBrain;

            if ($brandBrain !== null) {
                $lines[] = '';
                $lines[] = $brandBrain->toGrokContext()['markdown'];
            }
        }

        $lines[] = '';
        $lines[] = 'Platform caption limits (characters): '.collect(Platform::cases())
            ->map(fn ($platform) => $platform->label().'='.$platform->captionLimit())
            ->implode(', ');

        return implode("\n", $lines);
    }

    private function buildCaptionsUserPrompt(Post $post): string
    {
        $lines = [
            'Suggest 3 caption options for this post.',
            'Current draft caption: '.$this->guardrail->scrub($post->master_caption ?: '(none yet)'),
            'Target platforms: '.implode(', ', array_map(fn ($platform) => $platform->label(), $post->targetPlatforms())),
        ];

        if (! empty($post->hashtags)) {
            $lines[] = 'Existing hashtags: '.implode(', ', $post->hashtags);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"suggestions": [{"caption": string, "hashtags": string[], "altText": string|null, "firstComment": string|null}]}';

        return implode("\n", $lines);
    }

    private function buildRecommendPostsUserPrompt(Client $client): string
    {
        $campaigns = $client->campaigns()->get()->pluck('name')->filter()->implode(', ');

        $lines = [
            'Recommend 3-5 fresh post ideas for this client, tied to their active campaigns and brand pillars.',
            'Active campaigns: '.($campaigns !== '' ? $campaigns : '(none)'),
            '',
            'Respond with JSON: {"ideas": [{"title": string, "description": string, "platforms": string[], "suggestedCaption": string|null, "suggestedPostAt": string|null}]}',
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $platforms
     */
    private function buildDiscoverTrendsUserPrompt(Client $client, array $platforms): string
    {
        $lines = [
            'Discover current trends relevant to this client across the given platforms.',
            'Platforms: '.implode(', ', $platforms),
            'If you cannot browse the web, propose trend hypotheses and mark each with a confidence score (0.0-1.0).',
            '',
            'Respond with JSON: {"trends": [{"title": string, "summary": string, "platforms": string[], "confidence": number}]}',
        ];

        return implode("\n", $lines);
    }

    /**
     * Call DeepSeek's chat completions endpoint and parse/validate the structured JSON
     * response, retrying once (per Step 2.2: "Reject and retry up to 1 time if schema invalid")
     * when the response is missing, unparsable, or doesn't match the expected shape. Logs
     * `info` on start/end, `warning` on a schema retry, and `error` on the final failure —
     * never logging the API key or raw prompt contents.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function requestStructured(string $method, string $systemPrompt, string $userPrompt, callable $validator, array $context): ?array
    {
        $model = (string) config('ai.deepseek.model', 'deepseek-chat');

        Log::info('DeepSeek request started', [...$context, 'method' => $method, 'model' => $model]);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $payload = $this->callChatCompletions($systemPrompt, $userPrompt, $context, $method, $model);

            if ($payload !== null && $validator($payload)) {
                Log::info('DeepSeek request completed', [...$context, 'method' => $method, 'model' => $model, 'attempt' => $attempt]);

                return $payload;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                Log::warning('DeepSeek response failed schema validation, retrying', [
                    ...$context,
                    'method' => $method,
                    'model' => $model,
                    'attempt' => $attempt,
                ]);
            }
        }

        Log::error('DeepSeek request failed after retries', [...$context, 'method' => $method, 'model' => $model]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function callChatCompletions(string $systemPrompt, string $userPrompt, array $context, string $method, string $model): ?array
    {
        try {
            $response = $this->client()->post('/chat/completions', [
                'model' => $model,
                'max_tokens' => (int) config('ai.deepseek.max_tokens', 1024),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);
        } catch (RequestException|Throwable $e) {
            Log::error('DeepSeek HTTP request threw an exception', [
                ...$context,
                'method' => $method,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('DeepSeek request rejected', [
                ...$context,
                'method' => $method,
                'model' => $model,
                'status' => $response->status(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || $content === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function client(): PendingRequest
    {
        $apiKey = (string) config('ai.deepseek.api_key', '');
        $timeout = (int) config('ai.deepseek.timeout', 30);
        $baseUrl = (string) config('ai.deepseek.base_url', 'https://api.deepseek.com');

        return Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            // Trivial exponential backoff with jitter on 429/5xx, per Step 2.2.
            ->retry(3, fn (int $attempt): int => $this->backoffDelayMs($attempt), function (Throwable $exception) {
                if (! $exception instanceof RequestException) {
                    return true;
                }

                return $exception->response->status() === 429 || $exception->response->serverError();
            });
    }

    private function backoffDelayMs(int $attempt): int
    {
        $base = 200 * (2 ** ($attempt - 1));

        return $base + random_int(0, 100);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isValidCaptionSuggestionsShape(array $data): bool
    {
        if (! isset($data['suggestions']) || ! is_array($data['suggestions'])) {
            return false;
        }

        foreach ($data['suggestions'] as $item) {
            if (! is_array($item) || ! isset($item['caption']) || ! is_string($item['caption'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isValidPostIdeasShape(array $data): bool
    {
        if (! isset($data['ideas']) || ! is_array($data['ideas'])) {
            return false;
        }

        foreach ($data['ideas'] as $item) {
            if (! is_array($item) || ! isset($item['title'], $item['description'])
                || ! is_string($item['title']) || ! is_string($item['description'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function isValidTrendsShape(array $data): bool
    {
        if (! isset($data['trends']) || ! is_array($data['trends'])) {
            return false;
        }

        foreach ($data['trends'] as $item) {
            if (! is_array($item) || ! isset($item['title'], $item['summary'])
                || ! is_string($item['title']) || ! is_string($item['summary'])) {
                return false;
            }
        }

        return true;
    }
}
