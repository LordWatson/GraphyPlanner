<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Enums\Platform;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdea;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrend;
use App\Support\AI\AiTrends;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeepSeek AI provider (Step 2.2)
 *
 * Notes:
 * - We intentionally do NOT bind this in the container by default yet (Step 2.7 introduces provider selection).
 * - Secrets are never logged. Requests are logged with minimal context only.
 */
class DeepSeekProvider implements AiProvider
{
    public function suggestCaptions(Post $post): AiCaptionSuggestions
    {
        $payload = $this->buildChatPayload(
            system: 'You are an assistant that writes social media captions. Always output strict JSON matching the provided schema.',
            user: $this->buildCaptionPrompt($post),
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'suggestions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'caption' => ['type' => 'string'],
                                'hashtags' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'altText' => ['type' => ['string', 'null']],
                                'firstComment' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['caption'],
                        ],
                    ],
                ],
                'required' => ['suggestions'],
            ]
        );

        $json = $this->callDeepSeek('/chat/completions', $payload, 'suggestCaptions', [
            'post_id' => $post->id,
        ]);

        $items = [];
        foreach ((array) Arr::get($json, 'suggestions', []) as $s) {
            $caption = trim((string) ($s['caption'] ?? ''));
            if ($caption === '') {
                continue;
            }
            $hashtags = array_values(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : null, (array) ($s['hashtags'] ?? []))));
            $alt = isset($s['altText']) && is_string($s['altText']) ? $s['altText'] : null;
            $first = isset($s['firstComment']) && is_string($s['firstComment']) ? $s['firstComment'] : null;
            $items[] = new AiCaptionSuggestion($caption, $hashtags, $alt, $first);
        }

        return new AiCaptionSuggestions($items);
    }

    public function recommendPosts(Client $client): AiPostIdeas
    {
        $payload = $this->buildChatPayload(
            system: 'You are an assistant for social content ideation. Always output strict JSON matching the provided schema.',
            user: $this->buildIdeasPrompt($client),
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'ideas' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'description' => ['type' => ['string', 'null']],
                                'platforms' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'tags' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                            ],
                            'required' => ['title'],
                        ],
                    ],
                ],
                'required' => ['ideas'],
            ]
        );

        $json = $this->callDeepSeek('/chat/completions', $payload, 'recommendPosts', [
            'client_id' => $client->id,
        ]);

        $items = [];
        foreach ((array) Arr::get($json, 'ideas', []) as $i) {
            $title = trim((string) ($i['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $platforms = $this->mapPlatforms((array) ($i['platforms'] ?? []));
            $tags = array_values(array_filter(array_map(fn ($t) => is_string($t) ? trim($t) : null, (array) ($i['tags'] ?? []))));
            $items[] = new AiPostIdea(
                title: $title,
                description: isset($i['description']) && is_string($i['description']) ? $i['description'] : null,
                platforms: $platforms,
                tags: $tags,
            );
        }

        return new AiPostIdeas($items);
    }

    public function discoverTrends(Client $client, array $platforms): AiTrends
    {
        $payload = $this->buildChatPayload(
            system: 'You analyze social media trends. Always output strict JSON matching the provided schema.',
            user: $this->buildTrendsPrompt($client, $platforms),
            responseSchema: [
                'type' => 'object',
                'properties' => [
                    'trends' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'topic' => ['type' => 'string'],
                                'description' => ['type' => ['string', 'null']],
                                'platforms' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'confidence' => ['type' => ['number', 'null']],
                            ],
                            'required' => ['topic'],
                        ],
                    ],
                ],
                'required' => ['trends'],
            ]
        );

        $json = $this->callDeepSeek('/chat/completions', $payload, 'discoverTrends', [
            'client_id' => $client->id,
        ]);

        $items = [];
        foreach ((array) Arr::get($json, 'trends', []) as $t) {
            $topic = trim((string) ($t['topic'] ?? ''));
            if ($topic === '') {
                continue;
            }
            $platformsMapped = $this->mapPlatforms((array) ($t['platforms'] ?? []));
            $confidence = isset($t['confidence']) && is_numeric($t['confidence']) ? max(0.0, min(1.0, (float) $t['confidence'])) : null;
            $items[] = new AiTrend(
                topic: $topic,
                description: isset($t['description']) && is_string($t['description']) ? $t['description'] : null,
                platforms: $platformsMapped,
                confidence: $confidence,
            );
        }

        return new AiTrends($items);
    }

    /**
     * @param array<int, string> $platforms
     * @return array<int, Platform>
     */
    protected function mapPlatforms(array $platforms): array
    {
        $out = [];
        foreach ($platforms as $p) {
            $p = strtolower(trim((string) $p));
            $mapped = match ($p) {
                'instagram' => Platform::Instagram,
                'tiktok' => Platform::TikTok,
                'facebook' => Platform::Facebook,
                'linkedin' => Platform::LinkedIn,
                default => null,
            };
            if ($mapped) {
                $out[] = $mapped;
            }
        }
        return $out;
    }

    /**
     * Build a generic chat completion payload for DeepSeek.
     * We instruct the model to return STRICT JSON, then we parse and validate locally.
     *
     * @param array<string, mixed>|null $responseSchema
     * @return array<string, mixed>
     */
    protected function buildChatPayload(string $system, string $user, ?array $responseSchema = null): array
    {
        $cfg = config('ai.deepseek');
        return [
            'model' => $cfg['model'] ?? 'deepseek-chat',
            'max_tokens' => $cfg['max_tokens'] ?? 800,
            'temperature' => 0.7,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user.(
                    $responseSchema ? "\n\nReturn ONLY JSON matching this JSON Schema, no prose outside the JSON:\n".json_encode($responseSchema) : ''
                )],
            ],
            // Some providers accept explicit JSON response format; DeepSeek compatibility retained via instruction above
        ];
    }

    protected function buildCaptionPrompt(Post $post): string
    {
        $brand = $this->brandBrainFor($post->client_id);
        $brandText = $brand?->toGrokContext()['markdown'] ?? '';
        $postBits = [
            'Compose 3-5 concise caption options for this post.',
            'Include relevant hashtags but keep within common platform limits.',
            'Avoid any prohibited topics or tones from the brand brain.',
        ];
        return trim(implode("\n", [
            $brandText,
            '---',
            'POST CONTEXT:',
            '- Title: '.($post->title ?? '—'),
            '- Caption: '.($post->caption ?? '—'),
            '- Scheduled at: '.($post->scheduled_at ?? '—'),
            '',
            implode("\n", $postBits),
        ]));
    }

    protected function buildIdeasPrompt(Client $client): string
    {
        $brand = $this->brandBrainFor($client->id);
        $brandText = $brand?->toGrokContext()['markdown'] ?? '';
        return trim(implode("\n", [
            $brandText,
            '---',
            'TASK: Suggest 5-8 topical post ideas aligned with the brand’s audience and pillars. For each, include an optional one-line description, recommended platforms, and 1-3 tags.',
        ]));
    }

    /**
     * @param array<int, Platform> $platforms
     */
    protected function buildTrendsPrompt(Client $client, array $platforms): string
    {
        $brand = $this->brandBrainFor($client->id);
        $brandText = $brand?->toGrokContext()['markdown'] ?? '';
        $platformList = $platforms ? implode(', ', array_map(fn (Platform $p) => $p->label(), $platforms)) : 'Instagram, TikTok, Facebook, LinkedIn';
        return trim(implode("\n", [
            $brandText,
            '---',
            'TASK: Identify 3-6 currently relevant social content trends for: '.$platformList.'. Include brief description and optional confidence 0..1.',
        ]));
    }

    protected function brandBrainFor(?int $clientId): ?BrandBrain
    {
        if (!$clientId) {
            return null;
        }
        return BrandBrain::query()->where('client_id', $clientId)->first();
    }

    /**
     * Execute the HTTP call with a single retry/backoff on 429/5xx, and parse the JSON content.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $logCtx
     * @return array<string, mixed>
     */
    protected function callDeepSeek(string $endpoint, array $payload, string $op, array $logCtx = []): array
    {
        $cfg = config('ai.deepseek');
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://api.deepseek.com'), '/');
        $timeout = (int) ($cfg['timeout'] ?? 15);
        $apiKey = (string) ($cfg['api_key'] ?? '');

        $http = Http::baseUrl($baseUrl)
            ->timeout($timeout)
            ->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);

        $safeCtx = array_merge(['op' => $op], $logCtx);
        Log::info('DeepSeek request start', $safeCtx);

        $response = $http->post($endpoint, $payload);
        if ($this->shouldRetry($response->status())) {
            $delayMs = random_int(200, 600);
            usleep($delayMs * 1000);
            Log::warning('DeepSeek transient failure, retrying once', $safeCtx + ['status' => $response->status()]);
            $response = $http->post($endpoint, $payload);
        }

        if (!$response->ok()) {
            Log::error('DeepSeek request failed', $safeCtx + ['status' => $response->status()]);
            return [];
        }

        // DeepSeek chat response structure compatibility: try common "choices[0].message.content"
        $content = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($content) || trim($content) === '') {
            // Fallback to direct JSON body if provider already returned a parsed object
            $json = $response->json();
            return is_array($json) ? $json : [];
        }

        $parsed = $this->extractJsonObject($content);
        if ($parsed === null) {
            // one more best-effort: sometimes assistants wrap JSON in code fences
            $parsed = $this->extractJsonFromFences($content);
        }

        if (!is_array($parsed)) {
            Log::warning('DeepSeek returned non-JSON content', $safeCtx);
            return [];
        }

        return $parsed;
    }

    protected function shouldRetry(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * Extract the first top-level JSON object from a string, or null.
     * @return array<string, mixed>|null
     */
    protected function extractJsonObject(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Attempt to strip Markdown code fences and parse JSON within.
     * @return array<string, mixed>|null
     */
    protected function extractJsonFromFences(string $text): ?array
    {
        if (preg_match('/```(?:json)?\n(.*?)\n```/s', $text, $m)) {
            $decoded = json_decode($m[1] ?? '', true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
