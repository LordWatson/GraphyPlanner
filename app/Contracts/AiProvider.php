<?php

namespace App\Contracts;

use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;

interface AiProvider
{
    /**
     * Generate caption suggestions for the given post.
     */
    public function suggestCaptions(Post $post): AiCaptionSuggestions;

    /**
     * Recommend post ideas for the given client.
     */
    public function recommendPosts(Client $client): AiPostIdeas;

    /**
     * Discover trends for the given client across the provided platforms.
     *
     * @param array<int, \App\Enums\Platform> $platforms
     */
    public function discoverTrends(Client $client, array $platforms): AiTrends;
}
