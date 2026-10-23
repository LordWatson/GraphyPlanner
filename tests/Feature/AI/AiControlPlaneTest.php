<?php

namespace Tests\Feature\AI;

use App\Enums\AiRating;
use App\Enums\Role;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step M-7.1 — the AI control plane end to end: the per-org toggle hides every AI surface, the
 * monthly token budget is enforced with a clean message, every text call is metered with real
 * tokens, and the feedback loop records thumbs/implicit-applied idempotently.
 */
class AiControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.enabled', true);
    }

    private function owner(Organization $org): User
    {
        return User::factory()->for($org, 'organization')->role(Role::Owner)->create();
    }

    // --- Per-org toggle (dev plan Step 2.7, exit 2.13) -----------------------------------------

    public function test_ai_endpoints_are_hidden_when_the_org_toggle_is_off(): void
    {
        $org = Organization::factory()->aiDisabled()->create();
        $owner = $this->owner($org);
        $client = Client::factory()->for($org, 'organization')->create();

        $this->actingAs($owner)
            ->postJson(route('clients.ai.trends', $client))
            ->assertNotFound();
    }

    public function test_post_editor_hides_ai_when_the_org_toggle_is_off(): void
    {
        $org = Organization::factory()->aiDisabled()->create();
        $owner = $this->owner($org);
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $this->actingAs($owner)
            ->get(route('posts.edit', $post))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('ai.enabled', false)->where('ai.canUse', false));
    }

    // --- Usage metering (dev plan Step 2.8) ----------------------------------------------------

    public function test_a_caption_call_is_metered_with_real_tokens(): void
    {
        Config::set('ai.deepseek.api_key', 'sk-test');
        Config::set('ai.deepseek.base_url', 'https://api.deepseek.test');

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['content' => json_encode(['suggestions' => [['caption' => 'Hello', 'hashtags' => []]]])],
                ]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 80, 'total_tokens' => 200],
            ], 200),
        ]);

        $org = Organization::factory()->create();
        $owner = $this->owner($org);
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        // Sync queue runs the job during dispatch, so metering happens within the request.
        $this->actingAs($owner)->postJson(route('posts.ai.captions', $post))->assertAccepted();

        $this->assertDatabaseHas('ai_usage_logs', [
            'org_id' => $org->id,
            'client_id' => $client->id,
            'method' => 'suggestCaptions',
            'provider' => 'deepseek',
            'succeeded' => true,
            'prompt_tokens' => 120,
            'completion_tokens' => 80,
        ]);
    }

    public function test_budget_exceeded_returns_a_clean_422(): void
    {
        $org = Organization::factory()->create(['ai_monthly_token_budget' => 100]);
        $owner = $this->owner($org);
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        AiUsageLog::create([
            'org_id' => $org->id, 'provider' => 'deepseek', 'model' => 'deepseek-flash',
            'method' => 'suggestCaptions', 'succeeded' => true, 'prompt_tokens' => 80, 'completion_tokens' => 40,
        ]);

        $response = $this->actingAs($owner)->postJson(route('posts.ai.captions', $post));

        $response->assertStatus(422);
        $response->assertJson(['budgetExceeded' => true]);
        $response->assertJsonFragment(['message' => 'Monthly AI limit reached — contact your admin.']);
    }

    // --- Feedback loop (dev plan Step 2.9) -----------------------------------------------------

    public function test_feedback_is_recorded_and_idempotent_per_user_and_subject(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->owner($org);
        $client = Client::factory()->for($org, 'organization')->create();
        $trend = AiTrend::query()->create(['org_id' => $org->id, 'client_id' => $client->id, 'title' => 'T', 'summary' => 'S']);

        $this->actingAs($owner)->postJson(route('ai-trends.feedback', $trend), ['rating' => 'up'])
            ->assertOk()->assertJson(['rating' => 'up']);

        // Re-rating the same subject updates the one row rather than stacking.
        $this->actingAs($owner)->postJson(route('ai-trends.feedback', $trend), ['rating' => 'down', 'reason' => 'Too generic'])
            ->assertOk()->assertJson(['rating' => 'down']);

        $this->assertDatabaseCount('ai_feedback', 1);
        $this->assertDatabaseHas('ai_feedback', [
            'subject_type' => $trend->getMorphClass(),
            'subject_id' => $trend->id,
            'user_id' => $owner->id,
            'rating' => 'down',
            'reason' => 'Too generic',
        ]);
    }

    public function test_feedback_is_forbidden_for_a_designer_and_other_orgs(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $trend = AiTrend::query()->create(['org_id' => $org->id, 'client_id' => $client->id, 'title' => 'T', 'summary' => 'S']);

        $designer = User::factory()->for($org, 'organization')->role(Role::Designer)->create();
        $this->actingAs($designer)->postJson(route('ai-trends.feedback', $trend), ['rating' => 'up'])->assertForbidden();

        $otherOwner = $this->owner(Organization::factory()->create());
        $this->actingAs($otherOwner)->postJson(route('ai-trends.feedback', $trend), ['rating' => 'up'])->assertForbidden();
    }

    public function test_turning_an_idea_into_a_post_records_an_implicit_applied_signal(): void
    {
        $org = Organization::factory()->create();
        $owner = $this->owner($org);
        $client = Client::factory()->for($org, 'organization')->create();
        $idea = AiPostIdea::query()->create([
            'org_id' => $org->id, 'client_id' => $client->id, 'title' => 'Reel', 'description' => 'd', 'suggested_caption' => 'Cap',
        ]);

        $this->actingAs($owner)->post(route('ai-post-ideas.persist', $idea))->assertRedirect();

        $this->assertDatabaseHas('ai_feedback', [
            'subject_type' => $idea->getMorphClass(),
            'subject_id' => $idea->id,
            'user_id' => $owner->id,
            'rating' => AiRating::Applied->value,
        ]);
    }

    // --- Settings (dev plan Steps 2.7/2.8) -----------------------------------------------------

    public function test_settings_page_shows_the_controls_and_owner_can_update_them(): void
    {
        $org = Organization::factory()->create(['ai_enabled' => true, 'ai_monthly_token_budget' => null]);
        $owner = $this->owner($org);

        $this->actingAs($owner)->get(route('ai-usage.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('settings.ai_enabled', true)
                ->has('textUsage')
                ->has('performance'));

        $this->actingAs($owner)->put(route('ai-settings.update'), [
            'ai_enabled' => false,
            'ai_monthly_token_budget' => 500000,
        ])->assertRedirect(route('ai-usage.edit'));

        $org->refresh();
        $this->assertFalse($org->ai_enabled);
        $this->assertSame(500000, $org->ai_monthly_token_budget);
    }

    public function test_non_owner_cannot_update_ai_settings(): void
    {
        $org = Organization::factory()->create();
        $strategist = User::factory()->for($org, 'organization')->role(Role::Strategist)->create();

        $this->actingAs($strategist)->put(route('ai-settings.update'), [
            'ai_enabled' => false,
            'ai_monthly_token_budget' => null,
        ])->assertForbidden();
    }
}
