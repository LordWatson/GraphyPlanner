<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\Role;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Step 2.4 — AiController wires the Step 2.3 Actions up to the editor's "Suggest with AI" panel
 * and the client dashboard's "Trends & Ideas" panel. These tests assert the access-control shape
 * (feature flag, org scoping, Owner/Strategist only) and that the JSON responses carry the
 * structured props the frontend expects, without ever leaking secrets.
 */
class AiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
    }

    public function test_trends_endpoint_queues_a_job_and_the_poll_returns_structured_data(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->postJson(route('clients.ai.trends', $client), ['platforms' => ['instagram']]);

        $response->assertAccepted();
        $response->assertJsonStructure(['request_id', 'status']);

        // Sync queue runs the job during dispatch, so the poll already has the result.
        $poll = $this->actingAs($owner)->getJson(route('ai-text-jobs.status', $response->json('request_id')));
        $poll->assertOk();
        $poll->assertJsonPath('status', 'completed');
        $poll->assertJsonStructure(['result' => ['trends']]);
    }

    public function test_ideas_endpoint_queues_a_job_for_a_strategist(): void
    {
        $org = Organization::factory()->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($strategist)->postJson(route('clients.ai.ideas', $client));

        $response->assertAccepted();
        $response->assertJsonStructure(['request_id', 'status']);

        $poll = $this->actingAs($strategist)->getJson(route('ai-text-jobs.status', $response->json('request_id')));
        $poll->assertOk()->assertJsonPath('status', 'completed')->assertJsonStructure(['result' => ['ideas']]);
    }

    public function test_captions_endpoint_queues_a_job_for_an_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->postJson(route('posts.ai.captions', $post));

        $response->assertAccepted();
        $poll = $this->actingAs($owner)->getJson(route('ai-text-jobs.status', $response->json('request_id')));
        $poll->assertOk()->assertJsonPath('status', 'completed')->assertJsonStructure(['result' => ['suggestions', 'policyWarnings']]);
    }

    /**
     * Regression: with several targets, `Post::targetPlatforms()` walked `targets → socialAccount`
     * lazily and tripped `Model::preventLazyLoading()`. The job loads the relations first.
     */
    public function test_captions_job_works_for_a_post_with_multiple_targets(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        foreach ([Platform::Instagram, Platform::TikTok] as $platform) {
            $account = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => $platform]);
            PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
        }

        $response = $this->actingAs($owner)->postJson(route('posts.ai.captions', $post));
        $response->assertAccepted();

        $poll = $this->actingAs($owner)->getJson(route('ai-text-jobs.status', $response->json('request_id')));
        $poll->assertOk()->assertJsonPath('status', 'completed')->assertJsonStructure(['result' => ['suggestions', 'policyWarnings']]);
    }

    public function test_designer_is_forbidden_from_ai_endpoints(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($designer)->postJson(route('clients.ai.trends', $client));

        $response->assertForbidden();
    }

    public function test_user_from_another_org_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $otherOrg = Organization::factory()->create();
        $otherOwner = User::factory()->for($otherOrg, 'organization')->role(Role::Owner)->create();

        $response = $this->actingAs($otherOwner)->postJson(route('clients.ai.trends', $client));

        $response->assertForbidden();
    }

    public function test_ai_endpoints_are_not_found_when_the_feature_is_disabled(): void
    {
        Config::set('ai.enabled', false);

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        $response = $this->actingAs($owner)->postJson(route('clients.ai.trends', $client));

        $response->assertNotFound();
    }

    public function test_show_trend_renders_detail_page_for_an_owner(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $trend = AiTrend::query()->create([
            'org_id' => $org->id,
            'client_id' => $client->id,
            'title' => 'Short-form video',
            'summary' => 'Summary text',
            'platforms' => ['instagram'],
            'confidence' => 0.8,
        ]);

        $response = $this->actingAs($owner)->get(route('ai-trends.show', $trend));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('trend.title', 'Short-form video'));
    }

    public function test_show_idea_renders_detail_page_and_persist_creates_a_draft_post(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $idea = AiPostIdea::query()->create([
            'org_id' => $org->id,
            'client_id' => $client->id,
            'title' => 'Behind the scenes reel',
            'description' => 'Description text',
            'platforms' => ['instagram'],
            'suggested_caption' => 'Caption text',
        ]);

        $show = $this->actingAs($owner)->get(route('ai-post-ideas.show', $idea));
        $show->assertOk();
        $show->assertInertia(fn ($page) => $page->where('idea.title', 'Behind the scenes reel')->where('idea.post_id', null));

        $persist = $this->actingAs($owner)->post(route('ai-post-ideas.persist', $idea));

        $idea->refresh();
        $this->assertNotNull($idea->post_id);
        $persist->assertRedirect(route('posts.edit', $idea->post_id));
        $this->assertDatabaseHas('posts', [
            'id' => $idea->post_id,
            'client_id' => $client->id,
            'master_caption' => 'Caption text',
        ]);
    }

    public function test_designer_is_forbidden_from_trend_and_idea_detail_pages(): void
    {
        $org = Organization::factory()->create();
        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $trend = AiTrend::query()->create([
            'org_id' => $org->id,
            'client_id' => $client->id,
            'title' => 'Trend',
            'summary' => 'Summary',
        ]);

        $response = $this->actingAs($designer)->get(route('ai-trends.show', $trend));

        $response->assertForbidden();
    }

    public function test_post_editor_props_expose_ai_flags_without_secrets(): void
    {
        Config::set('ai.deepseek.api_key', 'secret-test-key-should-not-leak');

        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('ai.enabled', true)
            ->where('ai.canUse', true)
        );
        $response->assertDontSee(config('ai.deepseek.api_key'));
    }
}
