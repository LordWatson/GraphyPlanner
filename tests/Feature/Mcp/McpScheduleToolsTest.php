<?php

namespace Tests\Feature\Mcp;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Jobs\PublishPostJob;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\CancelScheduledPostTool;
use App\Mcp\Tools\SchedulePostTool;
use App\Mcp\Tools\StartAccountConnectionTool;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase MCP-4 — the elevated, irreversible tools (`WriteSchedule` scope, destructive-hinted, off by
 * default). They reuse the exact checklist-gated schedule/recall paths and the connect-invite flow
 * the web UI uses. These tests prove: scheduling re-runs the checklist and enqueues the publish job;
 * cancel takes a post off the schedule; the connect tool returns a URL and never a vendor token; and
 * a token without `WriteSchedule` cannot reach any of them.
 */
class McpScheduleToolsTest extends TestCase
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

    private function approvedPostReadyToSchedule(): Post
    {
        // A text-only-capable platform (X) so the "media attached" checklist item is satisfied
        // without seeding assets — the point here is the schedule path, not media validation.
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Approved,
            'master_caption' => 'Ready to go',
        ]);
        $account = SocialAccount::factory()->for($this->client)->connected()->create([
            'org_id' => $this->org->id,
            'platform' => Platform::X,
            'timezone' => 'Europe/London',
        ]);
        PostTarget::factory()->for($post)->for($account, 'socialAccount')->create([
            'scheduled_local_date' => now()->addWeek()->toDateString(),
            'scheduled_local_time' => '09:00:00',
            'scheduled_at_utc' => now()->addWeek()->setTime(9, 0),
        ]);

        return $post;
    }

    public function test_schedule_post_enqueues_the_publish_job_when_the_checklist_passes(): void
    {
        Queue::fake();
        $post = $this->approvedPostReadyToSchedule();

        GraphyServer::tool(SchedulePostTool::class, ['post_id' => $post->id])->assertOk();

        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);
        Queue::assertPushed(PublishPostJob::class);
    }

    public function test_cancel_takes_a_scheduled_post_off_the_schedule(): void
    {
        Queue::fake();
        $post = $this->approvedPostReadyToSchedule();
        GraphyServer::tool(SchedulePostTool::class, ['post_id' => $post->id])->assertOk();
        $this->assertSame(PostStatus::Scheduled, $post->refresh()->status);

        GraphyServer::tool(CancelScheduledPostTool::class, ['post_id' => $post->id])->assertOk();

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_start_account_connection_returns_a_url_and_no_vendor_token(): void
    {
        GraphyServer::tool(StartAccountConnectionTool::class, ['client_id' => $this->client->id])
            ->assertOk()
            ->assertSee('connect_url')
            ->assertDontSee('access_token')
            ->assertDontSee('refresh_token');
    }

    public function test_write_schedule_scope_is_required(): void
    {
        $post = $this->approvedPostReadyToSchedule();

        // A token with only write:drafts (not write:schedule) cannot schedule.
        $this->app->instance(McpContext::class, new McpContext(
            organization: $this->org,
            scopes: ['read', 'write:drafts'],
        ));

        GraphyServer::tool(SchedulePostTool::class, ['post_id' => $post->id])
            ->assertHasErrors()
            ->assertSee('write:schedule');

        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }
}
