<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use Illuminate\Support\Facades\Log;

/**
 * Temporary placeholder implementation of AiProvider (Step 2.1), bound by
 * default until provider selection (Step 2.7) switches the binding to a real
 * vendor (DeepSeekProvider, Step 2.2) when AI is enabled and configured.
 * Always returns empty result collections; never calls an external service.
 */
class NullAiProvider implements AiProvider
{
    public function suggestCaptions(Post $post): AiCaptionSuggestions
    {
        $this->logDisabled('suggestCaptions', ['post_id' => $post->id, 'client_id' => $post->client_id, 'org_id' => $post->org_id]);

        return new AiCaptionSuggestions;
    }

    public function recommendPosts(Client $client): AiPostIdeas
    {
        $this->logDisabled('recommendPosts', ['client_id' => $client->id, 'org_id' => $client->org_id]);

        return new AiPostIdeas;
    }

    public function discoverTrends(Client $client, array $platforms): AiTrends
    {
        $this->logDisabled('discoverTrends', ['client_id' => $client->id, 'org_id' => $client->org_id, 'platforms' => $platforms]);

        return new AiTrends;
    }

    /**
     * Makes "AI returns nothing" traceable to the provider binding itself: this class is only
     * resolved when AI_ENABLED is off or DEEPSEEK_API_KEY is empty (see AppServiceProvider).
     *
     * @param  array<string, mixed>  $context
     */
    private function logDisabled(string $method, array $context): void
    {
        Log::info('AI request answered by NullAiProvider (no vendor call made)', [
            ...$context,
            'method' => $method,
            'ai_enabled' => (bool) config('ai.enabled', false),
            'api_key_configured' => (string) config('ai.deepseek.api_key', '') !== '',
        ]);
    }
}
