<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;

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
        return new AiCaptionSuggestions();
    }

    public function recommendPosts(Client $client): AiPostIdeas
    {
        return new AiPostIdeas();
    }

    public function discoverTrends(Client $client, array $platforms): AiTrends
    {
        return new AiTrends();
    }
}
