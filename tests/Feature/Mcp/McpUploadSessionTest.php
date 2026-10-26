<?php

namespace Tests\Feature\Mcp;

use App\Actions\Assets\CreateAssetAction;
use App\Actions\Mcp\CreateMcpTokenAction;
use App\Enums\AssetSource;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\GetMediaUploadTool;
use App\Mcp\Tools\GetPostTool;
use App\Mcp\Tools\RequestMediaUploadTool;
use App\Models\Asset;
use App\Models\Client;
use App\Models\McpUploadSession;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // CreateAssetAction dispatches ProbeAssetJob.

        $this->org = Organization::factory()->create();
        config()->set('mcp.spike.dev_org_id', $this->org->id);

        $this->owner = User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Owner]);
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
            ->assertSee((string) $inLibrary->id)
            // These factory assets have no stored file, so there is no vision preview — metadata only.
            ->assertDontSee('/9j/');
    }

    public function test_get_media_upload_shows_the_uploaded_images_as_vision(): void
    {
        $post = $this->draftPost();

        $asset = app(CreateAssetAction::class)($this->client, $this->owner, [
            'source' => AssetSource::Upload,
            'file' => UploadedFile::fake()->image('hero.jpg', 600, 600),
        ]);
        $post->assets()->sync([$asset->id => ['sort_order' => 0]]);

        $session = McpUploadSession::factory()->for($this->org, 'organization')->for($this->client)->create([
            'post_ids' => [$post->id],
            'uploaded_asset_ids' => [$asset->id],
        ]);

        GraphyServer::tool(GetMediaUploadTool::class, ['session_id' => $session->id])
            ->assertOk()
            ->assertSee((string) $asset->id)   // the summary + the "Asset {id}" label
            ->assertSee('/9j/')                // a JPEG preview (base64 of FF D8 FF) — the model can see it
            // Even with the image present, no signed URL or storage path leaves the server.
            ->assertDontSee((string) $asset->path)
            ->assertDontSee('/files/');
    }

    public function test_get_media_upload_accepts_session_id_over_the_http_transport(): void
    {
        // Reproduces the real transport (not the in-process helper): a bearer-authenticated
        // JSON-RPC tools/call with session_id in arguments must reach the tool, not 'field is required'.
        config()->set('mcp.http.enabled', true);

        $session = McpUploadSession::factory()->for($this->org, 'organization')->for($this->client)->create([
            'post_ids' => [],
            'uploaded_asset_ids' => [],
        ]);

        ['token' => $token] = app(CreateMcpTokenAction::class)($this->org, 'Media connection', ['read', 'write:drafts']);

        $this->withToken($token)->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'get_media_upload',
                'arguments' => ['session_id' => $session->id],
            ],
        ])->assertOk()
            ->assertDontSee('is required', false)   // the bug report: "The session id field is required"
            ->assertSee('session_id', false)
            ->assertSee('open', false);
    }

    public function test_get_post_shows_attached_images_as_vision(): void
    {
        // The robust path: media is attached to the post on upload, so get_post (keyed by post_id,
        // which clients pass reliably) returns the images — no dependence on get_media_upload.
        $post = $this->draftPost();
        $asset = app(CreateAssetAction::class)($this->client, $this->owner, [
            'source' => AssetSource::Upload,
            'file' => UploadedFile::fake()->image('shot.jpg', 500, 500),
        ]);
        $post->assets()->sync([$asset->id => ['sort_order' => 0]]);

        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id])
            ->assertOk()
            ->assertSee((string) $asset->id)   // structured detail + the "Asset {id}" label
            ->assertSee('/9j/');               // a JPEG preview — the model can see the media

        // Opting out returns text only (no image preview).
        GraphyServer::tool(GetPostTool::class, ['post_id' => $post->id, 'include_media' => false])
            ->assertOk()
            ->assertDontSee('/9j/');
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
