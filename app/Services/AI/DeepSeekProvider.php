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
use App\Support\AI\ClientContextBuilder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DeepSeek-backed implementation of App\Contracts\AiProvider (Step 2.2). Builds a system
 * prompt from the client's company context (profile, full Brand Brain, current campaigns — see
 * ClientContextBuilder) plus platform caption-length constraints, and asks DeepSeek's chat
 * completions endpoint for strict JSON matching each method's DTO shape. Not bound by default — provider selection (Step 2.7)
 * decides when this (vs. App\Services\AI\NullAiProvider) is actually resolved against the
 * AiProvider contract.
 */
class DeepSeekProvider implements AiProvider
{
    /**
     * Schema is invalid/unparsable this many times before giving up and logging a failure.
     */
    private const MAX_ATTEMPTS = 2;

    /**
     * Upper bound when a truncated (`finish_reason = length`) attempt doubles max_tokens for its retry.
     */
    private const MAX_TOKENS_CEILING = 16000;

    /**
     * How many ideas/trends each request asks for (and keeps, if the model overshoots). Kept
     * small so a reasoning model's answer stays well inside max_tokens and responses stay fast.
     */
    private const IDEAS_PER_REQUEST = 3;

    private const TRENDS_PER_REQUEST = 3;

    public function __construct(
        private readonly AiGuardrailService $guardrail = new AiGuardrailService,
        private readonly ClientContextBuilder $context = new ClientContextBuilder,
    ) {}

    public function suggestCaptions(Post $post): AiCaptionSuggestions
    {
        $client = $post->client;

        $payload = $this->requestStructured(
            method: 'suggestCaptions',
            systemPrompt: $this->buildSystemPrompt($client),
            userPrompt: $this->buildCaptionsUserPrompt($post),
            validator: fn (array $data): ?string => $this->shapeError($data, 'suggestions', ['caption']),
            context: ['org_id' => $client?->org_id, 'client_id' => $client?->id, 'post_id' => $post->id],
        );

        if ($payload === null) {
            return new AiCaptionSuggestions;
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
            userPrompt: $this->buildRecommendPostsUserPrompt(),
            validator: fn (array $data): ?string => $this->shapeError($data, 'ideas', ['title', 'description']),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id],
        );

        if ($payload === null) {
            return new AiPostIdeas;
        }

        $ideas = collect($payload['ideas'] ?? [])
            ->take(self::IDEAS_PER_REQUEST)
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
            validator: fn (array $data): ?string => $this->shapeError($data, 'trends', ['title', 'summary']),
            context: ['org_id' => $client->org_id, 'client_id' => $client->id, 'platforms' => $platforms],
        );

        if ($payload === null) {
            return new AiTrends;
        }

        $trends = collect($payload['trends'] ?? [])
            ->take(self::TRENDS_PER_REQUEST)
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
     * Build the shared system prompt: the client's company context (profile, Brand Brain,
     * current campaigns) from ClientContextBuilder, explicit rules telling the model to ground
     * every result in it, plus platform caption-length constraints so every method stays
     * on-brand and platform-aware.
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
            $lines[] = 'Ground everything you propose in the company context below — generic advice that could apply to any brand is a failure:';
            $lines[] = '- Write in the Brand Brain voice (tone and personality) and never do anything listed under "Do not".';
            $lines[] = '- Speak to the described audience and their pain points, and reference the offer (value proposition, key products/services) where it fits naturally.';
            $lines[] = '- Tie each idea or trend angle to one of the content pillars and, where relevant, a current campaign.';
            $lines[] = '- Follow the hashtag policy: include every "Always use" hashtag in hashtag suggestions and never suggest a "Never use" one.';
            $lines[] = '- Write captions in the primary content language when one is given.';
            $lines[] = '';
            $lines[] = $this->context->build($client);
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
            'Suggest 3 caption options for this post, each written in the brand voice from the Brand Brain.',
            'Current draft caption: '.$this->guardrail->scrub($post->master_caption ?: '(none yet)'),
            'Target platforms: '.implode(', ', array_map(fn ($platform) => $platform->label(), $post->targetPlatforms())),
        ];

        if ($post->campaign !== null) {
            $lines[] = 'Campaign this post belongs to: '.$this->guardrail->scrub($this->context->describeCampaign($post->campaign));
        }

        if (! empty($post->hashtags)) {
            $lines[] = 'Existing hashtags: '.implode(', ', $post->hashtags);
        }

        $lines[] = '';
        $lines[] = 'Respond with JSON: {"suggestions": [{"caption": string, "hashtags": string[], "altText": string|null, "firstComment": string|null}]}';

        return implode("\n", $lines);
    }

    private function buildRecommendPostsUserPrompt(): string
    {
        $lines = [
            'Recommend exactly '.self::IDEAS_PER_REQUEST.' fresh post ideas for this client, tied to their current campaigns and content pillars.',
            'Each description must name the content pillar it serves and the audience pain point or offer it speaks to.',
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
            'Discover exactly '.self::TRENDS_PER_REQUEST.' current trends relevant to this client across the given platforms.',
            'Only include trends that fit the client\'s industry, markets, audience, and content pillars; each summary must explain how this specific brand could use the trend in its own voice.',
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
     * when the response is missing, unparsable, or doesn't match the expected shape. Every
     * failure path logs *why* it failed (HTTP status + vendor error, empty/truncated content,
     * JSON error, or the exact schema violation) alongside finish_reason/token usage/latency and
     * a scrubbed, truncated excerpt of the model's response — never the API key or the prompt.
     *
     * @param  callable(array<string, mixed>): ?string  $validator  returns null when valid, else the reason
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function requestStructured(string $method, string $systemPrompt, string $userPrompt, callable $validator, array $context): ?array
    {
        $model = (string) config('ai.deepseek.model', 'deepseek-chat');
        $context = [...$context, 'method' => $method, 'model' => $model];

        $maxTokens = (int) config('ai.deepseek.max_tokens', 1024);

        Log::info('DeepSeek request started', [
            ...$context,
            'max_tokens' => $maxTokens,
            'prompt_chars' => mb_strlen($systemPrompt) + mb_strlen($userPrompt),
        ]);

        $failure = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $result = $this->callChatCompletions($systemPrompt, $userPrompt, [...$context, 'attempt' => $attempt, 'max_tokens' => $maxTokens], $model, $maxTokens);

            if ($result['payload'] !== null) {
                $failure = $validator($result['payload']);

                if ($failure === null) {
                    Log::info('DeepSeek request completed', [...$context, ...$result['meta'], 'attempt' => $attempt]);

                    return $result['payload'];
                }

                Log::warning('DeepSeek response failed schema validation', [
                    ...$context,
                    ...$result['meta'],
                    'attempt' => $attempt,
                    'reason' => $failure,
                    'top_level_keys' => array_keys($result['payload']),
                    'response_excerpt' => $this->excerpt((string) json_encode($result['payload'])),
                ]);
            } else {
                $failure = $result['failure'];
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                // Re-sending a request that ran out of tokens with the same budget just fails the
                // same way (reasoning models spend it all thinking), so give the retry more room.
                $previousMaxTokens = $maxTokens;

                if (($result['meta']['finish_reason'] ?? null) === 'length') {
                    $maxTokens = min($maxTokens * 2, self::MAX_TOKENS_CEILING);
                }

                Log::warning('DeepSeek request retrying', [
                    ...$context,
                    'attempt' => $attempt,
                    'reason' => $failure,
                    'max_tokens' => $maxTokens,
                    'max_tokens_raised_from' => $maxTokens !== $previousMaxTokens ? $previousMaxTokens : null,
                ]);
            }
        }

        Log::error('DeepSeek request failed after retries', [...$context, 'attempts' => self::MAX_ATTEMPTS, 'last_reason' => $failure]);

        return null;
    }

    /**
     * One HTTP round trip. Returns the decoded JSON payload (or null) plus response metadata
     * (latency, finish_reason, token usage) for logging, and a short failure reason when the
     * payload couldn't be produced — each failure is also logged here with its own diagnostics.
     *
     * @param  array<string, mixed>  $context
     * @return array{payload: array<string, mixed>|null, failure: string|null, meta: array<string, mixed>}
     */
    private function callChatCompletions(string $systemPrompt, string $userPrompt, array $context, string $model, int $maxTokens): array
    {
        $startedAt = microtime(true);

        try {
            $response = $this->client()->post('/chat/completions', [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);
        } catch (Throwable $e) {
            $meta = ['duration_ms' => $this->elapsedMs($startedAt)];

            // Http::retry() throws a RequestException once retries are exhausted on a 4xx/5xx.
            $status = $e instanceof RequestException ? $e->response->status() : null;
            $vendorError = $e instanceof RequestException ? $this->vendorError($e->response->json()) : null;

            Log::error('DeepSeek HTTP request threw an exception', [
                ...$context,
                ...$meta,
                'exception' => $e::class,
                'error' => $this->guardrail->scrub($e->getMessage()),
                'status' => $status,
                'vendor_error' => $vendorError,
            ]);

            return ['payload' => null, 'failure' => 'http exception: '.($vendorError ?? $e::class), 'meta' => $meta];
        }

        $meta = [
            'duration_ms' => $this->elapsedMs($startedAt),
            'status' => $response->status(),
            'finish_reason' => $response->json('choices.0.finish_reason'),
            'usage' => array_filter([
                'prompt_tokens' => $response->json('usage.prompt_tokens'),
                'completion_tokens' => $response->json('usage.completion_tokens'),
                'reasoning_tokens' => $response->json('usage.completion_tokens_details.reasoning_tokens'),
                'total_tokens' => $response->json('usage.total_tokens'),
            ], fn ($value) => $value !== null),
        ];

        if (! $response->successful()) {
            $vendorError = $this->vendorError($response->json());

            Log::warning('DeepSeek request rejected', [
                ...$context,
                ...$meta,
                'vendor_error' => $vendorError,
                'response_excerpt' => $vendorError === null ? $this->excerpt($response->body()) : null,
            ]);

            return ['payload' => null, 'failure' => "http {$response->status()}: ".($vendorError ?? 'no error message'), 'meta' => $meta];
        }

        $truncated = $meta['finish_reason'] === 'length';
        $content = $response->json('choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            $reasoning = $response->json('choices.0.message.reasoning_content');

            Log::warning('DeepSeek returned empty content', [
                ...$context,
                ...$meta,
                'has_reasoning_content' => is_string($reasoning) && $reasoning !== '',
                'hint' => $truncated ? 'max_tokens was exhausted before any answer was written — raise DEEPSEEK_MAX_TOKENS' : null,
            ]);

            return ['payload' => null, 'failure' => $truncated ? 'empty content (truncated at max_tokens)' : 'empty content', 'meta' => $meta];
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            Log::warning('DeepSeek returned unparsable JSON', [
                ...$context,
                ...$meta,
                'json_error' => $e->getMessage(),
                'content_chars' => mb_strlen($content),
                'response_excerpt' => $this->excerpt($content),
                'hint' => $truncated ? 'response was cut off at max_tokens — raise DEEPSEEK_MAX_TOKENS' : null,
            ]);

            return ['payload' => null, 'failure' => $truncated ? 'invalid JSON (truncated at max_tokens)' : 'invalid JSON: '.$e->getMessage(), 'meta' => $meta];
        }

        if (! is_array($decoded)) {
            Log::warning('DeepSeek returned JSON that is not an object', [
                ...$context,
                ...$meta,
                'response_excerpt' => $this->excerpt($content),
            ]);

            return ['payload' => null, 'failure' => 'JSON is not an object', 'meta' => $meta];
        }

        return ['payload' => $decoded, 'failure' => null, 'meta' => $meta];
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

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Pull the human-readable message out of a DeepSeek/OpenAI-style error body, if any.
     */
    private function vendorError(mixed $body): ?string
    {
        $message = is_array($body) ? ($body['error']['message'] ?? $body['message'] ?? null) : null;

        return is_string($message) ? $this->guardrail->scrub($message) : null;
    }

    /**
     * Scrubbed, length-capped slice of a model/vendor response for log context: enough to see
     * what came back (wrong key, prose instead of JSON, cut-off output) without dumping it all.
     */
    private function excerpt(string $text, int $limit = 500): string
    {
        $text = $this->guardrail->scrub($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        // Keep the head and the tail: the head shows the shape, the tail shows where it was cut off.
        return mb_substr($text, 0, $limit - 150).' … '.mb_substr($text, -150);
    }

    /**
     * Shared shape check for every method's response: `$key` must be an array of objects, each
     * carrying the given string fields. Returns null when valid, otherwise a precise reason
     * (e.g. `ideas[2].description must be a string, got null`) for the log.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $requiredStringFields
     */
    private function shapeError(array $data, string $key, array $requiredStringFields): ?string
    {
        if (! array_key_exists($key, $data)) {
            return "missing top-level \"{$key}\" key";
        }

        if (! is_array($data[$key])) {
            return "\"{$key}\" must be an array, got ".get_debug_type($data[$key]);
        }

        foreach ($data[$key] as $index => $item) {
            if (! is_array($item)) {
                return "{$key}[{$index}] must be an object, got ".get_debug_type($item);
            }

            foreach ($requiredStringFields as $field) {
                if (! isset($item[$field]) || ! is_string($item[$field])) {
                    return "{$key}[{$index}].{$field} must be a string, got ".get_debug_type($item[$field] ?? null);
                }
            }
        }

        return null;
    }
}
