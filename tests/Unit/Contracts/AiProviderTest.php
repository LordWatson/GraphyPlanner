<?php

namespace Tests\Unit\Contracts;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\NullAiProvider;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    public function test_a_concrete_implementation_satisfies_the_contract(): void
    {
        $provider = new class implements AiProvider
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
        };

        $this->assertInstanceOf(AiProvider::class, $provider);
        $this->assertInstanceOf(AiCaptionSuggestions::class, $provider->suggestCaptions(new Post()));
        $this->assertInstanceOf(AiPostIdeas::class, $provider->recommendPosts(new Client()));
        $this->assertInstanceOf(AiTrends::class, $provider->discoverTrends(new Client(), ['instagram']));
    }

    public function test_the_container_resolves_ai_provider_to_the_null_provider_by_default(): void
    {
        $provider = $this->app->make(AiProvider::class);

        $this->assertInstanceOf(NullAiProvider::class, $provider);
        $this->assertSame([], $provider->suggestCaptions(new Post())->suggestions);
        $this->assertSame([], $provider->recommendPosts(new Client())->ideas);
        $this->assertSame([], $provider->discoverTrends(new Client(), ['instagram'])->trends);
    }
}
