<?php

namespace Tests\Feature\AI;

use App\Actions\AI\SuggestCaptionsFromMediaAction;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\AiUsageLog;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\AI\AiCaptionSuggestion;
use App\Support\AI\AiCaptionSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Step M-7.3 (dev plan I-9.4) — editor writing tools: rewrite (shorten/tone/adapt) and alt text
 * from an image, each gated/metered/guardrailed.
 */
class AiWritingToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
    }

    private function fakeDeepSeek(string $text): void
    {
        Config::set('ai.deepseek.api_key', 'sk-test');
        Config::set('ai.deepseek.base_url', 'https://api.deepseek.test');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['text' => $text])]]],
                'usage' => ['prompt_tokens' => 60, 'completion_tokens' => 40],
            ], 200),
        ]);
    }

    public function test_rewrite_returns_and_meters_a_shortened_caption(): void
    {
        $this->fakeDeepSeek('Short and punchy.');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->postJson(route('posts.ai.rewrite', $post), [
            'text' => 'A much longer original caption that rambles on and on and on.',
            'tool' => 'shorten',
        ]);

        $response->assertOk()->assertJson(['text' => 'Short and punchy.']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'org_id' => $org->id,
            'method' => 'rewrite',
            'provider' => 'deepseek',
            'succeeded' => true,
        ]);
    }

    public function test_adapt_clamps_to_the_platform_caption_limit(): void
    {
        $this->fakeDeepSeek(str_repeat('x', 5000));

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->postJson(route('posts.ai.rewrite', $post), [
            'text' => 'Original',
            'tool' => 'adapt',
            'platform' => Platform::X->value,
        ]);

        $response->assertOk();
        $this->assertLessThanOrEqual(Platform::X->captionLimit(), mb_strlen($response->json('text')));
    }

    public function test_rewrite_is_refused_over_budget(): void
    {
        $org = Organization::factory()->create(['ai_monthly_token_budget' => 100]);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        AiUsageLog::create([
            'org_id' => $org->id, 'provider' => 'deepseek', 'model' => 'deepseek-flash',
            'method' => 'rewrite', 'succeeded' => true, 'prompt_tokens' => 120, 'completion_tokens' => 0,
        ]);

        $this->actingAs($owner)->postJson(route('posts.ai.rewrite', $post), [
            'text' => 'Original', 'tool' => 'shorten',
        ])->assertStatus(422)->assertJson(['budgetExceeded' => true]);
    }

    public function test_rewrite_is_forbidden_for_a_designer(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($designer)->postJson(route('posts.ai.rewrite', $post), [
            'text' => 'Original', 'tool' => 'shorten',
        ])->assertForbidden();
    }

    public function test_rewrite_is_404_when_ai_is_disabled_for_the_org(): void
    {
        $org = Organization::factory()->aiDisabled()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)->postJson(route('posts.ai.rewrite', $post), [
            'text' => 'Original', 'tool' => 'shorten',
        ])->assertNotFound();
    }

    public function test_alt_text_returns_a_suggestion_from_the_image(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $asset = Asset::factory()->for($client)->create(['org_id' => $org->id]);

        $fake = Mockery::mock(SuggestCaptionsFromMediaAction::class);
        $fake->shouldReceive('__invoke')->andReturn(new AiCaptionSuggestions([
            new AiCaptionSuggestion('A caption', [], 'A latte on a marble counter', null),
        ]));
        $this->app->instance(SuggestCaptionsFromMediaAction::class, $fake);

        $this->actingAs($owner)->postJson(route('posts.ai.alt-text', ['post' => $post->id, 'asset' => $asset->id]))
            ->assertOk()
            ->assertJson(['altText' => 'A latte on a marble counter']);
    }

    public function test_alt_text_rejects_an_asset_from_another_client(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $otherAsset = Asset::factory()->for(Client::factory()->for($org, 'organization')->create())->create(['org_id' => $org->id]);

        $this->actingAs($owner)->postJson(route('posts.ai.alt-text', ['post' => $post->id, 'asset' => $otherAsset->id]))
            ->assertNotFound();
    }
}
