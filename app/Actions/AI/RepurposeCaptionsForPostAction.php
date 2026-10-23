<?php

namespace App\Actions\AI;

use App\Contracts\CaptionRepurposer;
use App\Enums\Platform;
use App\Models\Post;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\AiTextUsage;
use App\Services\AI\DeepSeekProvider;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.4 — repurpose a post's caption across its target platforms. Returns one native variant
 * per platform for the editor to drop into the per-platform override fields (I-3.1); nothing is
 * persisted — the user reviews and saves like any other edit.
 *
 * Two guarantees sit here, not in the provider: the result is guardrailed (banned-phrase variants
 * dropped, text scrubbed) and clamped to each platform's caption-length/hashtag limits (I-3.3), so a
 * variant can never exceed what the platform accepts regardless of what the model returned.
 */
class RepurposeCaptionsForPostAction
{
    public function __construct(
        private readonly CaptionRepurposer $repurposer,
        private readonly AiGuardrailService $guardrail,
        private readonly AiTextUsage $usage,
    ) {}

    /**
     * @return array<string, array{caption: string, hashtags: list<string>, firstComment: string|null, platform_label: string, caption_limit: int}>
     */
    public function __invoke(Post $post): array
    {
        $client = $post->client;

        if ($client === null) {
            return [];
        }

        $platforms = array_values(array_unique(array_map(fn (Platform $platform) => $platform->value, $post->targetPlatforms())));

        if ($platforms === []) {
            return [];
        }

        Log::info('Caption repurpose requested', ['org_id' => $post->org_id, 'client_id' => $post->client_id, 'post_id' => $post->id, 'platforms' => $platforms]);

        $startedAt = microtime(true);
        $raw = $this->repurposer->repurpose($post, $platforms);

        $deepSeek = $this->repurposer instanceof DeepSeekProvider;
        $this->usage->record(
            $client,
            'repurpose',
            $raw !== [],
            $startedAt,
            $deepSeek ? 'deepseek' : 'null',
            $deepSeek ? (string) config('ai.deepseek.model', 'deepseek-flash') : 'none',
            $deepSeek ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0.0,
        );

        $context = ['org_id' => $post->org_id, 'client_id' => $post->client_id, 'post_id' => $post->id];
        $result = [];

        foreach ($raw as $value => $variant) {
            $platform = Platform::tryFrom($value);

            if ($platform === null) {
                continue;
            }

            $suggestion = $this->guardrail->filterCaptionSuggestions(
                $client->brandBrain,
                new AiCaptionSuggestions([new AiCaptionSuggestion($variant['caption'], $variant['hashtags'], null, $variant['firstComment'])]),
                $context,
            )->suggestions[0] ?? null;

            if ($suggestion === null || trim($suggestion->caption) === '') {
                continue;
            }

            $result[$platform->value] = [
                'caption' => mb_substr($suggestion->caption, 0, $platform->captionLimit()),
                'hashtags' => array_slice($suggestion->hashtags, 0, $platform->maxHashtags()),
                'firstComment' => $suggestion->firstComment,
                'platform_label' => $platform->label(),
                'caption_limit' => $platform->captionLimit(),
            ];
        }

        return $result;
    }
}
