<?php

namespace Tests\Feature;

use App\Contracts\MusicProvider;
use App\Enums\AssetSource;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Exceptions\Publishing\MusicProviderUnavailableException;
use App\Models\Asset;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Publishing\MusicTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PostEditorControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Step 0.10 editor page exposes the full payload it needs: checklist, target accounts,
     * available assets, and only the transitions the acting user is authorized to make.
     */
    public function test_editor_page_exposes_post_checklist_and_allowed_transitions(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Draft]);

        $response = $this->actingAs($owner)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('post.id', $post->id)
            ->where('post.checklist.passed', false)
            ->has('allowedTransitions', 2)
            ->where('can.update', true)
        );
    }

    /**
     * Saving the editor form replaces the post's targets and attached media, and refreshes the
     * §6 checklist snapshot.
     */
    public function test_update_replaces_targets_and_assets_and_refreshes_checklist(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'master_caption' => null]);

        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
        ]);
        $asset = Asset::factory()->for($client)->create([
            'org_id' => $org->id,
            'source' => AssetSource::Url,
            'url' => 'https://example.com/image.png',
        ]);

        $response = $this->actingAs($owner)->put(route('posts.update', $post), [
            'master_caption' => 'Updated caption',
            'review_message' => 'Please double-check the hashtags before approving.',
            'asset_ids' => [$asset->id],
            'targets' => [
                [
                    'social_account_id' => $account->id,
                    'scheduled_local_date' => '2026-10-05',
                    'scheduled_local_time' => '10:00',
                ],
            ],
        ]);

        $response->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame('Updated caption', $post->master_caption);
        $this->assertSame('Please double-check the hashtags before approving.', $post->review_message);
        $this->assertSame([$asset->id], $post->assets()->pluck('assets.id')->all());

        $target = $post->targets()->firstOrFail();
        $this->assertSame($account->id, $target->social_account_id);
        $this->assertNotNull($target->scheduled_at_utc);

        // Caption + target + media all present now, so every checklist item should pass.
        $this->assertTrue($post->checklist_snapshot['passed']);
    }

    /**
     * Step 1.8.4 — the editor's music search field calls PostController::musicSearch, which
     * delegates to SearchMusicAction/MusicProvider and returns the matched tracks as JSON.
     */
    public function test_music_search_returns_tracks_from_the_music_provider_for_tiktok(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $track = new MusicTrack('track-1', 'Some Song', 'Some Artist', 'https://example.test/preview.mp3', Platform::TikTok);
        $provider = $this->createMock(MusicProvider::class);
        $provider->expects($this->once())
            ->method('search')
            ->with('lofi', Platform::TikTok)
            ->willReturn(new Collection([$track]));
        $this->app->instance(MusicProvider::class, $provider);

        $response = $this->actingAs($owner)->getJson(route('posts.music-search', $post).'?query=lofi&platform=tiktok');

        $response->assertOk();
        $response->assertJson([
            'tracks' => [
                ['id' => 'track-1', 'name' => 'Some Song', 'artist' => 'Some Artist', 'preview_url' => 'https://example.test/preview.mp3'],
            ],
        ]);
    }

    /**
     * Only Instagram/TikTok have any real vendor music lookup (Step 1.8.2) — any other platform
     * is rejected before ever reaching MusicProvider/SearchMusicAction.
     */
    public function test_music_search_short_circuits_for_platforms_without_a_music_lookup(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $provider = $this->createMock(MusicProvider::class);
        $provider->expects($this->never())->method('search');
        $this->app->instance(MusicProvider::class, $provider);

        $response = $this->actingAs($owner)->getJson(route('posts.music-search', $post).'?query=lofi&platform=facebook');

        $response->assertOk();
        $response->assertJson(['tracks' => []]);
    }

    /**
     * When the MusicProvider can't produce a real result (no connected account, vendor
     * rejection, etc.), `musicSearch` surfaces the reason as an `error` string instead of
     * silently returning an empty, unexplained result.
     */
    public function test_music_search_surfaces_the_music_provider_unavailable_reason(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $provider = $this->createMock(MusicProvider::class);
        $provider->method('search')->willThrowException(
            new MusicProviderUnavailableException('Connect a TikTok account before searching for music.'),
        );
        $this->app->instance(MusicProvider::class, $provider);

        $response = $this->actingAs($owner)->getJson(route('posts.music-search', $post).'?query=lofi&platform=tiktok');

        $response->assertOk();
        $response->assertJson([
            'tracks' => [],
            'error' => 'Connect a TikTok account before searching for music.',
        ]);
    }

    /**
     * Step 1.9.5 — when MetaGraphMusicProvider throws the "not connected" error with the
     * `meta_not_connected` code, musicSearch surfaces that distinguishable `error_code` so the
     * editor UI can key off it to render an inline "Connect Instagram" link instead of bare text.
     */
    public function test_music_search_surfaces_a_distinguishable_error_code_when_meta_is_not_connected(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $provider = $this->createMock(MusicProvider::class);
        $provider->method('search')->willThrowException(
            new MusicProviderUnavailableException(
                'Connect Instagram via Facebook Login before searching for music.',
                errorCode: 'meta_not_connected',
            ),
        );
        $this->app->instance(MusicProvider::class, $provider);

        $response = $this->actingAs($owner)->getJson(route('posts.music-search', $post).'?query=lofi&platform=instagram');

        $response->assertOk();
        $response->assertJson([
            'tracks' => [],
            'error' => 'Connect Instagram via Facebook Login before searching for music.',
            'error_code' => 'meta_not_connected',
        ]);
    }

    /**
     * A `Role::ClientReviewer` (or any role without `update` on the post) can still open the
     * editor and receives the full post payload (caption, hashtags, targets, assets) even though
     * `can.update` is false — the frontend renders it read-only rather than hiding it.
     */
    public function test_client_reviewer_can_view_but_not_update_their_clients_post(): void
    {
        $org = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $reviewer = User::factory()->for($org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $client->id]);
        $post = Post::factory()->for($client)->create([
            'org_id' => $org->id,
            'status' => PostStatus::WaitingClient,
            'master_caption' => 'Ready for review',
            // Post::booted() normalizes any leading "#" on save, so the stored/returned value is
            // always bare (see PostNormalizationTest for dedicated coverage of that behavior).
            'hashtags' => ['#launch'],
        ]);

        $response = $this->actingAs($reviewer)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('post.id', $post->id)
            ->where('post.master_caption', 'Ready for review')
            ->where('post.hashtags', ['launch'])
            ->where('can.update', false)
        );
    }

    /**
     * The editor's hashtag picker (Instagram-style autocomplete) is fed by the client's
     * `BrandBrain` "always use" hashtags plus every hashtag already used on the client's other
     * posts, deduped and with any leading "#" stripped.
     */
    public function test_editor_exposes_hashtag_suggestions_from_brand_brain_and_past_posts(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        BrandBrain::factory()->for($client)->create([
            'hashtag_policy' => ['always_use' => ['#brandalways', 'shared'], 'never_use' => [], 'rotation_notes' => null],
        ]);
        Post::factory()->for($client)->create(['org_id' => $org->id, 'hashtags' => ['shared', 'pastpost']]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $response = $this->actingAs($owner)->get(route('posts.edit', $post));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('hashtagSuggestions', ['brandalways', 'shared', 'pastpost'])
        );
    }

    public function test_designer_from_another_org_cannot_open_the_editor(): void
    {
        $org = Organization::factory()->create();
        $otherOrg = Organization::factory()->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);
        $otherDesigner = User::factory()->for($otherOrg, 'organization')->role(Role::Designer)->create();

        $response = $this->actingAs($otherDesigner)->get(route('posts.edit', $post));

        $response->assertForbidden();
    }

    /**
     * A `scheduled` or `published` post is locked for content edits: the editor still opens
     * (`view` isn't affected), but `can.update` must be false so the frontend renders it
     * read-only until the post is moved to a different status.
     */
    public function test_scheduled_and_published_posts_are_locked_for_editing(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        foreach ([PostStatus::Scheduled, PostStatus::Published] as $status) {
            $post = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => $status]);

            $response = $this->actingAs($owner)->get(route('posts.edit', $post));

            $response->assertOk();
            $response->assertInertia(fn ($page) => $page
                ->where('post.id', $post->id)
                ->where('can.update', false)
            );
        }
    }

    /**
     * Even a direct `PUT posts.update` request must be rejected while the post is `scheduled` or
     * `published`, since `UpdatePostRequest` authorizes via the same `PostPolicy::update`.
     */
    public function test_updating_a_scheduled_or_published_post_is_forbidden(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();

        foreach ([PostStatus::Scheduled, PostStatus::Published] as $status) {
            $post = Post::factory()->for($client)->create([
                'org_id' => $org->id,
                'status' => $status,
                'master_caption' => 'Original caption',
            ]);

            $response = $this->actingAs($owner)->put(route('posts.update', $post), [
                'master_caption' => 'Trying to sneak an edit in',
            ]);

            $response->assertForbidden();
            $this->assertSame('Original caption', $post->refresh()->master_caption);
        }
    }
}
