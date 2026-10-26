<?php

namespace Tests\Feature\Mcp;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Mcp\Servers\GraphyServer;
use App\Mcp\Tools\CreateDraftPostTool;
use App\Mcp\Tools\SetPostMediaTool;
use App\Mcp\Tools\UploadMediaTool;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use App\Support\Mcp\McpContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase MCP (media) — getting an image the user put into their AI assistant into Graphy and onto a
 * post. `upload_media` ingests base64 bytes through the EXACT web-upload safety pipeline (type/size/
 * content-sniffing/virus scan), so nothing the web uploader rejects can be stored; `set_post_media`
 * and `create_draft_post(asset_ids)` attach media via the same `UpdatePostAction` the editor uses.
 * These tests prove the happy path, that the safety rules still reject bad input over MCP, that media
 * is strictly client/org-scoped, and that the elevated `WriteDrafts` scope is required.
 */
class McpMediaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // ProbeAssetJob is dispatched by CreateAssetAction; don't run it here.

        $this->org = Organization::factory()->create(['default_timezone' => 'Europe/London']);
        config()->set('mcp.spike.dev_org_id', $this->org->id);

        User::factory()->create(['org_id' => $this->org->id, 'role' => Role::Owner]);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    public function test_upload_media_creates_an_asset_from_base64_without_leaking_a_url(): void
    {
        GraphyServer::tool(UploadMediaTool::class, [
            'client_id' => $this->client->id,
            'data' => self::pngBase64(),
            'filename' => 'hero.png',
        ])->assertOk();

        $asset = Asset::query()->where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame($this->client->id, $asset->client_id);
        $this->assertSame('hero.png', $asset->original_filename);
        $this->assertStringStartsWith('image/', (string) $asset->mime_type);
    }

    public function test_upload_media_rejects_a_renamed_non_image(): void
    {
        GraphyServer::tool(UploadMediaTool::class, [
            'client_id' => $this->client->id,
            'data' => base64_encode('<html><body>not an image</body></html>'),
            'filename' => 'evil.png',
        ])->assertHasErrors();

        $this->assertSame(0, Asset::query()->where('org_id', $this->org->id)->count());
    }

    public function test_upload_media_rejects_an_oversized_file(): void
    {
        config()->set('assets.max_upload_size_kb', 0); // any non-empty image now exceeds the ceiling

        GraphyServer::tool(UploadMediaTool::class, [
            'client_id' => $this->client->id,
            'data' => self::pngBase64(),
            'filename' => 'hero.png',
        ])->assertHasErrors();

        $this->assertSame(0, Asset::query()->where('org_id', $this->org->id)->count());
    }

    public function test_upload_media_requires_the_write_drafts_scope(): void
    {
        $this->app->instance(McpContext::class, new McpContext(
            organization: $this->org,
            scopes: ['read'],
        ));

        GraphyServer::tool(UploadMediaTool::class, [
            'client_id' => $this->client->id,
            'data' => self::pngBase64(),
            'filename' => 'hero.png',
        ])->assertHasErrors()->assertSee('write:drafts');

        $this->assertSame(0, Asset::query()->where('org_id', $this->org->id)->count());
    }

    public function test_create_draft_post_attaches_supplied_media(): void
    {
        $asset = Asset::factory()->for($this->org, 'organization')->for($this->client)->create();

        GraphyServer::tool(CreateDraftPostTool::class, [
            'client_id' => $this->client->id,
            'master_caption' => 'With media',
            'asset_ids' => [$asset->id],
        ])->assertOk();

        $post = Post::query()->where('org_id', $this->org->id)->firstOrFail();
        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertTrue($post->assets()->whereKey($asset->id)->exists());
    }

    public function test_set_post_media_replaces_adds_and_removes(): void
    {
        $a = Asset::factory()->for($this->org, 'organization')->for($this->client)->create();
        $b = Asset::factory()->for($this->org, 'organization')->for($this->client)->create();

        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
        ]);
        $post->assets()->sync([$a->id => ['sort_order' => 0]]);

        // replace: only B remains.
        GraphyServer::tool(SetPostMediaTool::class, [
            'post_id' => $post->id,
            'asset_ids' => [$b->id],
            'mode' => 'replace',
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$b->id], $post->assets()->pluck('assets.id')->all());

        // add: A is appended back.
        GraphyServer::tool(SetPostMediaTool::class, [
            'post_id' => $post->id,
            'asset_ids' => [$a->id],
            'mode' => 'add',
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $post->assets()->pluck('assets.id')->all());

        // remove: B is detached.
        GraphyServer::tool(SetPostMediaTool::class, [
            'post_id' => $post->id,
            'asset_ids' => [$b->id],
            'mode' => 'remove',
        ])->assertOk();
        $this->assertEqualsCanonicalizing([$a->id], $post->assets()->pluck('assets.id')->all());
    }

    public function test_set_post_media_refuses_another_clients_asset(): void
    {
        $otherClient = Client::factory()->for($this->org, 'organization')->create();
        $foreign = Asset::factory()->for($this->org, 'organization')->for($otherClient)->create();

        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
        ]);

        GraphyServer::tool(SetPostMediaTool::class, [
            'post_id' => $post->id,
            'asset_ids' => [$foreign->id],
        ])->assertHasErrors();

        $this->assertSame(0, $post->assets()->count());
    }

    public function test_set_post_media_requires_the_write_drafts_scope(): void
    {
        $this->app->instance(McpContext::class, new McpContext(
            organization: $this->org,
            scopes: ['read'],
        ));

        $asset = Asset::factory()->for($this->org, 'organization')->for($this->client)->create();
        $post = Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
        ]);

        GraphyServer::tool(SetPostMediaTool::class, [
            'post_id' => $post->id,
            'asset_ids' => [$asset->id],
        ])->assertHasErrors()->assertSee('write:drafts');

        $this->assertSame(0, $post->assets()->count());
    }

    /**
     * A real, minimal PNG as base64 — so the `mimes:` rule and `SafeUpload` content sniff both pass.
     */
    private static function pngBase64(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return base64_encode($png);
    }
}
