<?php

namespace Tests\Feature;

use App\Contracts\MusicProvider;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\UploadPostAdapter;
use App\Support\Publishing\MusicTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 1.8.5 §12 acceptance test: a real music selection (as returned by the editor's music
 * search, Step 1.8.4) flows unchanged through `Post::music` into `UploadPostAdapter`'s vendor
 * payload — `audio_id` for Instagram, `tiktok_music_id` for TikTok — with no adapter changes.
 */
class MusicSelectionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_selected_tiktok_track_round_trips_into_tiktok_music_id(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::TikTok,
            'external_account_id' => 'ext-tiktok-1',
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $track = new MusicTrack('vendor-track-42', 'Real Song', 'Real Artist', 'https://example.test/preview.mp3', Platform::TikTok);
        $provider = $this->createMock(MusicProvider::class);
        $provider->expects($this->once())
            ->method('search')
            ->with('lofi', Platform::TikTok)
            ->willReturn(new Collection([$track]));
        $this->app->instance(MusicProvider::class, $provider);

        // 1. Editor searches and gets back the real vendor track (Step 1.8.4).
        $searchResponse = $this->actingAs($owner)
            ->getJson(route('posts.music-search', $post).'?query=lofi&platform=tiktok');
        $searchResponse->assertOk();
        $selected = $searchResponse->json('tracks.0');
        $this->assertSame('vendor-track-42', $selected['id']);

        // 2. Editor saves the selection onto the post, unchanged, as `Post::music`.
        $updateResponse = $this->actingAs($owner)->put(route('posts.update', $post), [
            'master_caption' => $post->master_caption ?? 'Caption',
            'music' => ['id' => $selected['id'], 'name' => $selected['name']],
            'targets' => [
                ['social_account_id' => $account->id],
            ],
        ]);
        $updateResponse->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame('vendor-track-42', $post->music['id'] ?? null);

        // 3. Publishing reaches the vendor with `tiktok_music_id` set to the selected track's id,
        // via UploadPostAdapter::applyTikTokFields()'s existing, unchanged mapping.
        Http::fake([
            '*/upload_text' => Http::response(['request_id' => 'up-tiktok'], 200),
        ]);
        $post->load('targets', 'assets');

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            $fields = $request->data();

            return $request->url() === 'https://api.upload-post.com/api/upload_text'
                && ($fields['tiktok_music_id'] ?? null) === 'vendor-track-42';
        });
    }

    public function test_a_selected_instagram_track_round_trips_into_audio_id(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-ig-1',
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        $track = new MusicTrack('ig-track-7', 'IG Song', 'IG Artist', 'https://example.test/ig.mp3', Platform::Instagram);
        $provider = $this->createMock(MusicProvider::class);
        $provider->expects($this->once())
            ->method('search')
            ->with('vibes', Platform::Instagram)
            ->willReturn(new Collection([$track]));
        $this->app->instance(MusicProvider::class, $provider);

        $searchResponse = $this->actingAs($owner)
            ->getJson(route('posts.music-search', $post).'?query=vibes&platform=instagram');
        $searchResponse->assertOk();
        $selected = $searchResponse->json('tracks.0');
        $this->assertSame('ig-track-7', $selected['id']);

        $updateResponse = $this->actingAs($owner)->put(route('posts.update', $post), [
            'master_caption' => $post->master_caption ?? 'Caption',
            'music' => ['id' => $selected['id'], 'name' => $selected['name']],
            'targets' => [
                ['social_account_id' => $account->id],
            ],
        ]);
        $updateResponse->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame('ig-track-7', $post->music['id'] ?? null);

        Http::fake([
            '*/upload_text' => Http::response(['request_id' => 'up-ig'], 200),
        ]);
        $post->load('targets', 'assets');

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            $fields = $request->data();

            return $request->url() === 'https://api.upload-post.com/api/upload_text'
                && ($fields['audio_id'] ?? null) === 'ig-track-7';
        });
    }
}
