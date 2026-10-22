<?php

namespace Tests\Unit\Contracts;

use App\Contracts\AiProvider;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\NullAiProvider;
use App\Support\AI\AiBrandProfileDraft;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use App\Support\AI\BrandProfileInput;
use App\Support\AI\Images\AiImageBrief;
use App\Support\AI\Images\ImageBriefInput;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    public function test_a_concrete_implementation_satisfies_the_contract(): void
    {
        $provider = new class implements AiProvider
        {
            public function suggestCaptions(Post $post): AiCaptionSuggestions
            {
                return new AiCaptionSuggestions;
            }

            public function recommendPosts(Client $client): AiPostIdeas
            {
                return new AiPostIdeas;
            }

            public function discoverTrends(Client $client, array $platforms): AiTrends
            {
                return new AiTrends;
            }

            public function draftImageBrief(Client $client, ImageBriefInput $input): AiImageBrief
            {
                return new AiImageBrief(subject: $input->title);
            }

            public function draftBrandProfile(BrandProfileInput $input): AiBrandProfileDraft
            {
                return AiBrandProfileDraft::empty();
            }
        };

        $this->assertInstanceOf(AiProvider::class, $provider);
        $this->assertInstanceOf(AiCaptionSuggestions::class, $provider->suggestCaptions(new Post));
        $this->assertInstanceOf(AiPostIdeas::class, $provider->recommendPosts(new Client));
        $this->assertInstanceOf(AiTrends::class, $provider->discoverTrends(new Client, ['instagram']));
    }

    public function test_the_container_resolves_ai_provider_to_the_null_provider_by_default(): void
    {
        $provider = $this->app->make(AiProvider::class);

        $this->assertInstanceOf(NullAiProvider::class, $provider);
        $this->assertSame([], $provider->suggestCaptions(new Post)->suggestions);
        $this->assertSame([], $provider->recommendPosts(new Client)->ideas);
        $this->assertSame([], $provider->discoverTrends(new Client, ['instagram'])->trends);
    }

    public function test_the_null_provider_logs_why_no_vendor_call_was_made(): void
    {
        config(['ai.enabled' => true, 'ai.deepseek.api_key' => '']);
        Log::spy();

        (new NullAiProvider)->recommendPosts(new Client);

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'AI request answered by NullAiProvider (no vendor call made)'
            && $context['method'] === 'recommendPosts'
            && $context['ai_enabled'] === true
            && $context['api_key_configured'] === false);
    }

    public function test_the_container_resolves_ai_provider_to_null_when_disabled_even_with_an_api_key(): void
    {
        config(['ai.enabled' => false, 'ai.deepseek.api_key' => 'some-key']);

        $provider = $this->app->make(AiProvider::class);

        $this->assertInstanceOf(NullAiProvider::class, $provider);
    }

    public function test_the_container_resolves_ai_provider_to_null_when_enabled_without_an_api_key(): void
    {
        config(['ai.enabled' => true, 'ai.deepseek.api_key' => '']);

        $provider = $this->app->make(AiProvider::class);

        $this->assertInstanceOf(NullAiProvider::class, $provider);
    }

    public function test_the_container_resolves_ai_provider_to_deep_seek_when_enabled_and_configured(): void
    {
        config(['ai.enabled' => true, 'ai.deepseek.api_key' => 'some-key']);

        $provider = $this->app->make(AiProvider::class);

        $this->assertInstanceOf(DeepSeekProvider::class, $provider);
    }
}
