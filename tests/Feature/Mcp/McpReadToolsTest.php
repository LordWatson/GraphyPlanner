<?php

namespace Tests\Feature\Mcp;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\GetCalendarTool;
use App\Mcp\Tools\GetClientTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\ListPostsTool;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase MCP-2 — the read tools reuse the exact web-UI Actions/queries, run org-scoped as the token's
 * acting member, and render every scheduled time in both the account's local timezone and the org
 * timezone. These tests prove scoping (a tool never reaches another org's row) and the London/Dubai
 * invariant (a post's two targets report independent local times) — the MCP mirror of the calendar
 * invariant. In-process invocation falls back to the configured dev org and its Owner, which stands
 * in for the authenticated token's org + member on the HTTP transport.
 */
class McpReadToolsTest extends TestCase
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
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Northwind Coffee']);
    }

    public function test_get_client_returns_the_profile_and_health(): void
    {
        GraphyServer::tool(GetClientTool::class, ['client_id' => $this->client->id])
            ->assertOk()
            ->assertSee('Northwind Coffee')
            ->assertSee('health');
    }

    public function test_get_client_cannot_reach_another_orgs_client(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreign = Client::factory()->for($otherOrg, 'organization')->create(['name' => 'Rival Studio']);

        GraphyServer::tool(GetClientTool::class, ['client_id' => $foreign->id])
            ->assertHasErrors()
            ->assertDontSee('Rival Studio');
    }

    public function test_list_posts_is_scoped_to_the_org(): void
    {
        Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
            'master_caption' => 'Our own draft',
        ]);

        $otherOrg = Organization::factory()->create();
        $foreignClient = Client::factory()->for($otherOrg, 'organization')->create();
        Post::factory()->for($otherOrg, 'organization')->for($foreignClient)->create([
            'status' => PostStatus::Draft,
            'master_caption' => 'Rival draft',
        ]);

        GraphyServer::tool(ListPostsTool::class, [])
            ->assertOk()
            ->assertSee('Our own draft')
            ->assertDontSee('Rival draft');
    }

    public function test_get_post_reports_each_targets_independent_local_time(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
            'master_caption' => 'Same instant, two cities',
        ]);

        // One UTC instant, two targets in different timezones: 09:00Z is 09:00 in London (GMT) and
        // 13:00 in Dubai (UTC+4). Each target carries its own stored local time.
        $instant = '2026-11-01T09:00:00+00:00';

        $london = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'Europe/London']);
        $dubai = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'Asia/Dubai']);

        PostTarget::factory()->for($post)->for($london, 'socialAccount')->create([
            'scheduled_local_date' => '2026-11-01',
            'scheduled_local_time' => '09:00:00',
            'scheduled_at_utc' => $instant,
        ]);
        PostTarget::factory()->for($post)->for($dubai, 'socialAccount')->create([
            'scheduled_local_date' => '2026-11-01',
            'scheduled_local_time' => '13:00:00',
            'scheduled_at_utc' => $instant,
        ]);

        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertSee('Europe/London')
            ->assertSee('Asia/Dubai')
            ->assertSee('09:00:00')
            ->assertSee('13:00:00');
    }

    public function test_get_calendar_renders_the_window_in_both_timezones(): void
    {
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Scheduled,
        ]);
        $dubai = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'timezone' => 'Asia/Dubai']);
        PostTarget::factory()->for($post)->for($dubai, 'socialAccount')->create([
            'scheduled_local_date' => '2026-11-10',
            'scheduled_local_time' => '13:00:00',
            'scheduled_at_utc' => '2026-11-10T09:00:00+00:00',
        ]);

        GraphyServer::tool(GetCalendarTool::class, ['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertOk()
            ->assertSee('Asia/Dubai')
            ->assertSee('Europe/London');
    }
}
