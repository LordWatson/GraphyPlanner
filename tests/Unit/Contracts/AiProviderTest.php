<?php

namespace Tests\Unit\Contracts;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    public function test_container_resolves_ai_provider_and_methods_return_expected_dtos(): void
    {
        $provider = app(AiProvider::class);

        $this->assertInstanceOf(AiProvider::class, $provider);

        // Use plain model instances to avoid touching the database in a unit test
        $client = new Client();
        $post = new Post();

        $captions = $provider->suggestCaptions($post);
        $this->assertInstanceOf(AiCaptionSuggestions::class, $captions);
        $this->assertCount(0, $captions);

        $ideas = $provider->recommendPosts($client);
        $this->assertInstanceOf(AiPostIdeas::class, $ideas);
        $this->assertCount(0, $ideas);

        $trends = $provider->discoverTrends($client, []);
        $this->assertInstanceOf(AiTrends::class, $trends);
        $this->assertCount(0, $trends);
    }
}
