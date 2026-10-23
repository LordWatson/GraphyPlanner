<?php

namespace Tests\Unit\Services\AI;

use App\Contracts\AiProvider;
use App\Enums\CampaignStatus;
use App\Models\BrandBrain;
use App\Models\Campaign;
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
        Config::set('ai.deepseek.model', 'deepseek-flash');
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
        $this->assertInstanceOf(AiProvider::class, new DeepSeekProvider);
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

        $result = (new DeepSeekProvider)->suggestCaptions($post);

        $this->assertInstanceOf(AiCaptionSuggestions::class, $result);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame('Great caption!', $result->suggestions[0]->caption);
        $this->assertSame(['graphy'], $result->suggestions[0]->hashtags);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.deepseek.test/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-super-secret-key')
                && $request['model'] === 'deepseek-flash'
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

        $result = (new DeepSeekProvider)->recommendPosts($client);

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

        $result = (new DeepSeekProvider)->discoverTrends($client, ['tiktok']);

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

        $result = (new DeepSeekProvider)->suggestCaptions($post);

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

        $result = (new DeepSeekProvider)->suggestCaptions($post);

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

        $result = (new DeepSeekProvider)->suggestCaptions($post);

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

        (new DeepSeekProvider)->suggestCaptions($post);

        Log::shouldNotHaveReceived('info', function (string $message, array $context = []) {
            return str_contains(json_encode($context), 'sk-super-secret-key');
        });
    }

    public function test_a_schema_failure_logs_the_exact_reason_and_the_keys_that_came_back(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'post_ideas' => [['title' => 'Wrong key']],
            ]), 200),
        ]);
        Log::spy();

        $result = (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        $this->assertSame([], $result->ideas);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek response failed schema validation'
            && $context['reason'] === 'missing top-level "ideas" key'
            && $context['top_level_keys'] === ['post_ideas']
            && str_contains($context['response_excerpt'], 'Wrong key'));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek request failed after retries'
            && $context['last_reason'] === 'missing top-level "ideas" key');
    }

    public function test_a_schema_failure_names_the_offending_item_and_field(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'ideas' => [
                    ['title' => 'Fine', 'description' => 'Fine'],
                    ['title' => 'Broken', 'description' => null],
                ],
            ]), 200),
        ]);
        Log::spy();

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek response failed schema validation'
            && $context['reason'] === 'ideas[1].description must be a string, got null');
    }

    public function test_truncated_json_is_logged_with_finish_reason_usage_and_a_max_tokens_hint(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"ideas": [{"title": "Cut off'], 'finish_reason' => 'length']],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 3000, 'total_tokens' => 3900],
            ], 200),
        ]);
        Log::spy();

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek returned unparsable JSON'
            && $context['finish_reason'] === 'length'
            && $context['usage']['completion_tokens'] === 3000
            && str_contains((string) $context['hint'], 'DEEPSEEK_MAX_TOKENS')
            && str_contains($context['response_excerpt'], 'Cut off'));
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek request failed after retries'
            && $context['last_reason'] === 'invalid JSON (truncated at max_tokens)');
    }

    public function test_empty_content_is_logged_with_reasoning_diagnostics(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '', 'reasoning_content' => 'Thinking…'], 'finish_reason' => 'length']],
            ], 200),
        ]);
        Log::spy();

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek returned empty content'
            && $context['has_reasoning_content'] === true
            && $context['finish_reason'] === 'length');
    }

    public function test_recommend_posts_asks_for_and_keeps_at_most_three_ideas(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'ideas' => array_map(fn (int $i) => ['title' => "Idea {$i}", 'description' => 'Desc'], range(1, 5)),
            ]), 200),
        ]);

        $result = (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        $this->assertSame(['Idea 1', 'Idea 2', 'Idea 3'], array_map(fn ($idea) => $idea->title, $result->ideas));
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'Recommend exactly 3 fresh post ideas'));
    }

    public function test_discover_trends_asks_for_and_keeps_at_most_three_trends(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse([
                'trends' => array_map(fn (int $i) => ['title' => "Trend {$i}", 'summary' => 'Sum', 'platforms' => ['tiktok'], 'confidence' => 0.5], range(1, 5)),
            ]), 200),
        ]);

        $result = (new DeepSeekProvider)->discoverTrends(Client::factory()->create(), ['tiktok']);

        $this->assertSame(['Trend 1', 'Trend 2', 'Trend 3'], array_map(fn ($trend) => $trend->title, $result->trends));
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'Discover exactly 3 current trends'));
    }

    public function test_a_retry_after_running_out_of_tokens_doubles_max_tokens(): void
    {
        Config::set('ai.deepseek.max_tokens', 3000);

        // Mirrors a reasoning model burning the whole budget thinking before writing any answer.
        Http::fakeSequence('*/chat/completions')
            ->push([
                'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
                'usage' => ['completion_tokens' => 3000, 'completion_tokens_details' => ['reasoning_tokens' => 3000]],
            ], 200)
            ->push($this->chatResponse(['ideas' => [['title' => 'Idea', 'description' => 'Desc']]]), 200);
        Log::spy();

        $result = (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        $this->assertCount(1, $result->ideas);
        $sentMaxTokens = Http::recorded()->map(fn (array $pair) => $pair[0]['max_tokens'])->all();
        $this->assertSame([3000, 6000], $sentMaxTokens);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek request retrying'
            && $context['max_tokens'] === 6000
            && $context['max_tokens_raised_from'] === 3000);
    }

    public function test_a_retry_for_a_non_truncation_failure_keeps_the_same_max_tokens(): void
    {
        Config::set('ai.deepseek.max_tokens', 3000);
        Http::fake(['*/chat/completions' => Http::response($this->chatResponse(['unexpected' => 'shape']), 200)]);

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        $this->assertSame([3000, 3000], Http::recorded()->map(fn (array $pair) => $pair[0]['max_tokens'])->all());
    }

    public function test_a_vendor_rejection_logs_the_vendor_error_message(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response(['error' => ['message' => 'Model Not Exist']], 400),
        ]);
        Log::spy();

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek HTTP request threw an exception'
            && $context['status'] === 400
            && $context['vendor_error'] === 'Model Not Exist');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek request failed after retries'
            && $context['last_reason'] === 'http exception: Model Not Exist');
    }

    public function test_a_successful_request_logs_latency_finish_reason_and_token_usage(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['ideas' => []])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ], 200),
        ]);
        Log::spy();

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => $message === 'DeepSeek request completed'
            && $context['finish_reason'] === 'stop'
            && $context['usage'] === ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15]
            && is_int($context['duration_ms']));
    }

    public function test_response_excerpts_are_scrubbed_of_pii(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response($this->chatResponse(['oops' => 'mail leak@example.com'])),
        ]);
        Log::spy();

        (new DeepSeekProvider)->recommendPosts(Client::factory()->create());

        Log::shouldNotHaveReceived('warning', fn (string $message, array $context = []) => str_contains((string) json_encode($context), 'leak@example.com'));
    }

    private function clientWithBrandBrain(): Client
    {
        $client = Client::factory()->create(['name' => 'Acme Fitness', 'industry' => 'Fitness']);
        BrandBrain::factory()->for($client)->create([
            'voice' => ['tone' => 'Playful and bold', 'personality' => 'Coach', 'do_nots' => ['Never shame bodies']],
            'audience' => ['description' => 'Busy parents', 'demographics' => '30-45', 'pain_points' => ['No time to train']],
            'hashtag_policy' => ['always_use' => ['#AcmeMoves'], 'never_use' => [], 'rotation_notes' => null],
            'content_pillars' => ['Quick workouts'],
        ]);

        return $client;
    }

    private function assertBrandBrainGroundedSystemPrompt(): void
    {
        Http::assertSent(function ($request) {
            $system = $request['messages'][0]['content'];

            return $request['messages'][0]['role'] === 'system'
                && str_contains($system, 'Ground everything you propose in the company context below')
                && str_contains($system, '- Name: Acme Fitness')
                && str_contains($system, 'Playful and bold')
                && str_contains($system, 'No time to train')
                && str_contains($system, '#AcmeMoves')
                && str_contains($system, 'Quick workouts');
        });
    }

    public function test_suggest_captions_grounds_the_prompt_in_the_brand_brain_and_post_campaign(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->chatResponse(['suggestions' => []]), 200)]);

        $client = $this->clientWithBrandBrain();
        $campaign = Campaign::factory()->for($client)->create(['name' => 'Spring Launch', 'goal' => 'Drive app signups']);
        $post = Post::factory()->create(['client_id' => $client->id, 'campaign_id' => $campaign->id]);

        (new DeepSeekProvider)->suggestCaptions($post);

        $this->assertBrandBrainGroundedSystemPrompt();
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'Campaign this post belongs to: Spring Launch')
            && str_contains($request['messages'][1]['content'], 'goal: Drive app signups'));
    }

    public function test_recommend_posts_grounds_the_prompt_in_the_brand_brain_and_campaigns(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->chatResponse(['ideas' => []]), 200)]);

        $client = $this->clientWithBrandBrain();
        Campaign::factory()->for($client)->create(['name' => 'Spring Launch', 'status' => CampaignStatus::Active]);

        (new DeepSeekProvider)->recommendPosts($client);

        $this->assertBrandBrainGroundedSystemPrompt();
        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'Spring Launch (Active)')
            && str_contains($request['messages'][1]['content'], 'name the content pillar it serves'));
    }

    public function test_discover_trends_grounds_the_prompt_in_the_brand_brain(): void
    {
        Http::fake(['*/chat/completions' => Http::response($this->chatResponse(['trends' => []]), 200)]);

        (new DeepSeekProvider)->discoverTrends($this->clientWithBrandBrain(), ['tiktok']);

        $this->assertBrandBrainGroundedSystemPrompt();
        Http::assertSent(fn ($request) => str_contains($request['messages'][1]['content'], 'how this specific brand could use the trend'));
    }
}
