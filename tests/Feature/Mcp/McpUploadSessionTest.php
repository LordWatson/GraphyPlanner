<?php

namespace Tests\Feature\Mcp;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\GetMediaUploadTool;
use App\Mcp\Tools\RequestMediaUploadTool;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpUploadSession;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MCP media (batch upload link). `request_media_upload` mints one browser link covering a client and
 * a fixed set of its posts (never returning the raw token); `get_media_upload` lets the assistant see
 * what landed on each post (and any library uploads). These tests prove the mint + scoping + scope
 * gate and the per-post/library reporting. The browser upload itself is covered by McpUploadPageTest.
 */
class McpUploadSessionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        config()->set('mcp.spike.dev_org_id', $this->org->id);

        User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Owner]);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    private function draftPost(): Post
    {
        return Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
        ]);
    }

    public function test_request_media_upload_mints_one_link_for_multiple_posts(): void
    {
        $ids = [$this->draftPost()->id, $this->draftPost()->id, $this->draftPost()->id];

        GraphyServer::tool(RequestMediaUploadTool::class, [
            'client_id' => $this->client->id,
            'post_ids' => $ids,
        ])->assertOk()->assertSee('mcp-upload');

        $session = McpUploadSession::query()->where('org_id', $this->org->id)->firstOrFail();
        $this->assertEqualsCanonicalizing($ids, $session->post_ids);
        $this->assertSame(64, strlen($session->token_hash));
    }

    public function test_request_media_upload_refuses_a_post_from_another_client(): void
    {
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $foreignPost = Post::factory()->for($this->org, 'organization')->for($otherClient)->create();

        GraphyServer::tool(RequestMediaUploadTool::class, [
            'client_id' => $this->client->id,
            'post_ids' => [$this->draftPost()->id, $foreignPost->id],
        ])->assertHasErrors();

        $this->assertSame(0, McpUploadSession::query()->where('org_id', $this->org->id)->count());
    }

    public function test_request_media_upload_refuses_another_orgs_client(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();

        GraphyServer::tool(RequestMediaUploadTool::class, ['client_id' => $otherClient->id])
            ->assertHasErrors();

        $this->assertSame(0, McpUploadSession::query()->withoutOrgScope()->where('org_id', $this->org->id)->count());
    }

    public function test_request_media_upload_requires_the_write_drafts_scope(): void
    {
        $this->app->instance(McpContext::class, new McpContext(
            organization: $this->org,
            scopes: ['read'],
        ));

        GraphyServer::tool(RequestMediaUploadTool::class, ['client_id' => $this->client->id])
            ->assertHasErrors()
            ->assertSee('write:drafts');

        $this->assertSame(0, McpUploadSession::query()->withoutOrgScope()->where('org_id', $this->org->id)->count());
    }

    public function test_get_media_upload_reports_each_post_and_the_library(): void
    {
        $post = $this->draftPost();
        $onPost = Asset::factory()->for($this->org, 'organization')->for($this->client)->create();
        $inLibrary = Asset::factory()->for($this->org, 'organization')->for($this->client)->create();

        $post->assets()->sync([$onPost->id => ['sort_order' => 0]]);

        $session = McpUploadSession::factory()->for($this->org, 'organization')->for($this->client)->create([
            'post_ids' => [$post->id],
            'uploaded_asset_ids' => [$onPost->id, $inLibrary->id],
        ]);

        GraphyServer::tool(GetMediaUploadTool::class, ['session_id' => $session->id])
            ->assertOk()
            ->assertSee('open')
            ->assertSee((string) $onPost->id)
            ->assertSee((string) $inLibrary->id);
    }

    public function test_get_media_upload_is_org_scoped(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherClient = Client::factory()->for($otherOrg, 'organization')->create();
        $foreign = McpUploadSession::factory()->for($otherOrg, 'organization')->for($otherClient)->create();

        GraphyServer::tool(GetMediaUploadTool::class, ['session_id' => $foreign->id])
            ->assertHasErrors();
    }
}
