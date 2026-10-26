<?php

namespace Tests\Feature\Mcp;

use App\Actions\Mcp\CreateMcpUploadSessionAction;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\McpUploadSession;
use App\Models\Organization;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * MCP media — the public, token-gated batch upload page. No login: possession of a live
 * `McpUploadSession` is the capability. These tests prove the page renders the target posts and 410s
 * when dead, that files route to the named post (and append in order), that a post outside the
 * session is refused, that library uploads work, that `SafeUpload` rejections and the per-session
 * file cap apply, and that one session takes many files.
 */
class McpUploadPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Queue::fake(); // CreateAssetAction dispatches ProbeAssetJob.

        $this->org = Organization::factory()->create();
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
        $this->client = Client::factory()->for($this->org, 'organization')->create(['name' => 'Saffron Kitchen']);
    }

    private function draftPost(): Post
    {
        return Post::factory()->for($this->org, 'organization')->for($this->client)->create([
            'status' => PostStatus::Draft,
        ]);
    }

    /**
     * @param  list<int>  $postIds
     * @return array{0: string, 1: McpUploadSession}
     */
    private function makeSession(array $postIds = []): array
    {
        $result = app(CreateMcpUploadSessionAction::class)($this->client, $this->owner, $postIds);

        return [$result['token'], $result['session']];
    }

    public function test_the_page_renders_the_target_posts(): void
    {
        $post = $this->draftPost();
        [$token] = $this->makeSession([$post->id]);

        $this->get(route('mcp-upload.show', $token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('mcp-upload/show')
                ->where('client.name', 'Saffron Kitchen')
                ->has('posts', 1)
                ->where('posts.0.id', $post->id));
    }

    public function test_an_expired_or_unknown_link_is_gone(): void
    {
        [$token, $session] = $this->makeSession([$this->draftPost()->id]);
        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(route('mcp-upload.show', $token))->assertStatus(410);
        $this->get(route('mcp-upload.show', 'unknown-token'))->assertStatus(410);
    }

    public function test_uploading_routes_to_the_named_post_and_appends_in_order(): void
    {
        $post = $this->draftPost();
        [$token, $session] = $this->makeSession([$post->id]);

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->image('first.jpg'),
            'post_id' => $post->id,
        ])->assertCreated();

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->image('second.jpg'),
            'post_id' => $post->id,
        ])->assertCreated();

        $ordered = $post->assets()->orderByPivot('sort_order')->pluck('original_filename')->all();
        $this->assertSame(['first.jpg', 'second.jpg'], $ordered);
        $this->assertSame(2, count($session->refresh()->uploaded_asset_ids));
    }

    public function test_a_post_outside_the_session_is_refused(): void
    {
        $inSession = $this->draftPost();
        $outside = $this->draftPost();
        [$token] = $this->makeSession([$inSession->id]);

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->image('x.jpg'),
            'post_id' => $outside->id,
        ])->assertStatus(403);

        $this->assertSame(0, $outside->assets()->count());
    }

    public function test_library_upload_with_no_post(): void
    {
        [$token, $session] = $this->makeSession(); // no posts

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->image('lib.jpg'),
        ])->assertCreated();

        $this->assertSame(1, $this->client->assets()->count());
        $this->assertSame(1, count($session->refresh()->uploaded_asset_ids));
    }

    public function test_a_disallowed_file_is_rejected(): void
    {
        [$token, $session] = $this->makeSession();

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
        ])->assertStatus(422);

        $this->assertSame(0, $this->client->assets()->count());
        $this->assertSame(0, count($session->refresh()->uploaded_asset_ids));
    }

    public function test_the_per_session_file_cap_is_enforced(): void
    {
        config()->set('mcp.upload.max_files', 1);
        [$token] = $this->makeSession();

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->image('one.jpg'),
        ])->assertCreated();

        $this->postJson(route('mcp-upload.store', $token), [
            'file' => UploadedFile::fake()->image('two.jpg'),
        ])->assertStatus(422);

        $this->assertSame(1, $this->client->assets()->count());
    }
}
