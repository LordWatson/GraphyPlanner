<?php

namespace App\Services\AI;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;

/**
 * Placeholder AI provider used before a real implementation is configured.
 * Returns empty suggestion/result DTOs.
 */
class NullAiProvider implements AiProvider
{
    public function suggestCaptions(Post $post): AiCaptionSuggestions
    {
        return AiCaptionSuggestions::empty();
    }

    public function recommendPosts(Client $client): AiPostIdeas
    {
        return AiPostIdeas::empty();
    }

    public function discoverTrends(Client $client, array $platforms): AiTrends
    {
        return AiTrends::empty();
    }
}
