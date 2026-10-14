<?php

namespace Tests\Unit\Services\AI;

use App\Contracts\AiProvider;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Post;
use App\Services\AI\DeepSeekProvider;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DeepSeekProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.deepseek.api_key', 'sk-super-secret-key');
        Config::set('ai.deepseek.model', 'deepseek-chat');
        Config::set('ai.deepseek.base_url', 'https://api.deepseek.test');
        Config::set('ai.deepseek.timeout', 5);
    }

    private function chatResponse(array $json): array
    {
        return [
            'choices' => [
                ['message' => ['content' => json_encode($json)]],
            ],
        ];
    }

    public function test_it_satisfies_the_ai_provider_contract(): void
    {
        $this->assertInstanceOf(AiProvider::class, new DeepSeekProvider());
    }

    public function test_suggest_captions_returns_parsed_suggestions_on_a_valid_response(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'suggestions' => [
                    ['caption' => 'Great caption!', 'hashtags' => ['graphy'], 'altText' => 'alt', 'firstComment' => 'first'],
                ],
            ]), 200),
        ]);

        $client = Client::factory()->create(['industry' => 'Fitness']);
        BrandBrain::factory()->for($client)->create();
        $post = Post::factory()->create(['client_id' => $client->id, 'master_caption' => 'Draft']);

        $result = (new DeepSeekProvider())->suggestCaptions($post);

        $this->assertInstanceOf(AiCaptionSuggestions::class, $result);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame('Great caption!', $result->suggestions[0]->caption);
        $this->assertSame(['graphy'], $result->suggestions[0]->hashtags);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.deepseek.test/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-super-secret-key')
                && $request['model'] === 'deepseek-chat'
                && $request['response_format'] === ['type' => 'json_object'];
        });
    }

    public function test_recommend_posts_returns_parsed_ideas_on_a_valid_response(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'ideas' => [
                    ['title' => 'Idea 1', 'description' => 'Do a thing', 'platforms' => ['instagram']],
                ],
            ]), 200),
        ]);

        $client = Client::factory()->create();

        $result = (new DeepSeekProvider())->recommendPosts($client);

        $this->assertInstanceOf(AiPostIdeas::class, $result);
        $this->assertCount(1, $result->ideas);
        $this->assertSame('Idea 1', $result->ideas[0]->title);
    }

    public function test_discover_trends_returns_parsed_trends_on_a_valid_response(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'trends' => [
                    ['title' => 'Trend 1', 'summary' => 'Something is trending', 'platforms' => ['tiktok'], 'confidence' => 0.8],
                ],
            ]), 200),
        ]);

        $client = Client::factory()->create();

        $result = (new DeepSeekProvider())->discoverTrends($client, ['tiktok']);

        $this->assertInstanceOf(AiTrends::class, $result);
        $this->assertCount(1, $result->trends);
        $this->assertSame('Trend 1', $result->trends[0]->title);
        $this->assertSame(0.8, $result->trends[0]->confidence);
    }

    public function test_it_retries_once_when_the_first_response_fails_schema_validation_then_succeeds(): void
    {
        Http::fakeSequence('*/chat/completions')
            ->push($this->chatResponse(['unexpected' => 'shape']), 200)
            ->push($this->chatResponse(['suggestions' => [['caption' => 'Second try caption']]]), 200);

        $client = Client::factory()->create();
        $post = Post::factory()->create(['client_id' => $client->id]);

        $result = (new DeepSeekProvider())->suggestCaptions($post);

        $this->assertCount(1, $result->suggestions);
        $this->assertSame('Second try caption', $result->suggestions[0]->caption);
        Http::assertSentCount(2);
    }

    public function test_it_returns_an_empty_dto_when_schema_remains_invalid_after_the_retry(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse(['unexpected' => 'shape']), 200),
        ]);

        $client = Client::factory()->create();
        $post = Post::factory()->create(['client_id' => $client->id]);

        $result = (new DeepSeekProvider())->suggestCaptions($post);

        $this->assertSame([], $result->suggestions);
        Http::assertSentCount(2);
    }

    public function test_it_returns_an_empty_dto_when_the_vendor_rejects_the_request(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => 'bad request'], 400),
        ]);

        $client = Client::factory()->create();
        $post = Post::factory()->create(['client_id' => $client->id]);

        $result = (new DeepSeekProvider())->suggestCaptions($post);

        $this->assertSame([], $result->suggestions);
    }

    public function test_it_never_logs_the_api_key(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'suggestions' => [['caption' => 'Hello']],
            ]), 200),
        ]);

        Log::spy();

        $client = Client::factory()->create();
        $post = Post::factory()->create(['client_id' => $client->id]);

        (new DeepSeekProvider())->suggestCaptions($post);

        Log::shouldNotHaveReceived('info', function (string $message, array $context = []) {
            return str_contains(json_encode($context), 'sk-super-secret-key');
        });
    }
}
