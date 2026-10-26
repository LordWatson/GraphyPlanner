<?php

namespace Tests\Feature\Mcp;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\CreateDraftPostTool;
use App\Mcp\Tools\TransitionPostStatusTool;
use App\Mcp\Tools\UpdatePostTool;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase MCP-3 — the human-in-the-loop write tools (drafts & edits, `WriteDrafts` scope). They reuse
 * the exact CreatePostAction/UpdatePostAction/TransitionPostStatusAction the web UI calls, so the
 * checklist, the status map, transactions and logging all apply. These tests prove: a draft lands
 * org-scoped with independent per-target times; edits round-trip; the status map (and the ≤approved
 * cap) rejects illegal/over-cap moves; and a read-only token is rejected by the scope gate before
 * any Action runs.
 */
class McpWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['default_timezone' => 'Europe/London']);
        config()->set('mcp.spike.dev_org_id', $this->org->id);

        User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Owner]);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_create_draft_post_lands_a_draft_with_independent_target_times(): void
    {
        $london = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'Europe/London']);
        $dubai = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'Asia/Dubai']);

        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'master_caption' => 'Launch day!',
            'hashtags' => ['launch', 'coffee'],
            'targets' => [
                ['social_account_id' => $london->id, 'scheduled_local_date' => '2026-11-01', 'scheduled_local_time' => '09:00'],
                ['social_account_id' => $dubai->id, 'scheduled_local_date' => '2026-11-01', 'scheduled_local_time' => '09:00'],
            ],
        ])->assertOk();

        $post = Post::query()->where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame('Launch day!', $post->master_caption);

        // Each target keeps its own local time; the derived UTC instants differ by the 4h offset.
        $times = $post->targets()->with('socialAccount')->get();
        $this->assertCount(2, $times);
        $this->assertNotEquals(
            $times[0]->scheduled_at_utc?->toIso8601String(),
            $times[1]->scheduled_at_utc?->toIso8601String(),
        );
    }

    public function test_update_post_round_trips_the_caption(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
            'master_caption' => 'Old caption',
        ]);

        GraphyServer::tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'master_caption' => 'New caption',
        ])->assertOk()->assertSee('New caption');

        $this->assertSame('New caption', $post->refresh()->master_caption);
    }

    public function test_transition_moves_a_draft_forward(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create(['status' => PostStatus::Draft]);

        GraphyServer::tool(TransitionPostStatusTool::class, [
            'post_id' => $post->id,
            'status' => PostStatus::InternalReview->value,
        ])->assertOk();

        $this->assertSame(PostStatus::InternalReview, $post->refresh()->status);
    }

    public function test_transition_rejects_an_illegal_move(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create(['status' => PostStatus::Idea]);

        // idea → approved is not in the transition map.
        GraphyServer::tool(TransitionPostStatusTool::class, [
            'post_id' => $post->id,
            'status' => PostStatus::Approved->value,
        ])->assertHasErrors();

        $this->assertSame(PostStatus::Idea, $post->refresh()->status);
    }

    public function test_transition_cannot_reach_scheduled_via_mcp(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create(['status' => PostStatus::Approved]);

        // "scheduled" isn't even an accepted argument on this tool (capped at approved).
        GraphyServer::tool(TransitionPostStatusTool::class, [
            'post_id' => $post->id,
            'status' => 'scheduled',
        ])->assertHasErrors();

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_a_read_only_token_is_rejected_from_creating_a_draft(): void
    {
        // Bind a read-only context the way AuthenticateMcp does on HTTP: the scope gate must reject
        // this before CreatePostAction runs.
        $this->app->instance(McpContext::class, new McpContext(
            organization: $this->org,
            scopes: ['read'],
        ));

        GraphyServer::tool(CreateDraftPostTool::class, ['client_id' => $this->client->id])
            ->assertHasErrors()
            ->assertSee('write:drafts');

        $this->assertSame(0, Post::query()->where('org_id', $this->org->id)->count());
    }
}
