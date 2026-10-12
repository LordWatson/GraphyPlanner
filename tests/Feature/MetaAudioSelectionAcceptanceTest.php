<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\MetaGraphMusicProvider;
use App\Services\Publishing\UploadPostAdapter;
use App\Services\Publishing\UploadPostMusicProvider;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 1.9.6 §12 acceptance test: repeats the Step 1.8.5 pattern specifically for Instagram,
 * proving the new MetaGraphMusicProvider search path (Step 1.9.4, against a faked Graph
 * response) still feeds the unchanged UploadPostAdapter::publish() `audio_id` mapping (Step 1.2).
 *
 * @throws BindingResolutionException
 */
class MetaAudioSelectionAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_selected_instagram_meta_track_round_trips_into_audio_id(): void
    {
        $org = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($org, 'organization')->role(Role::Owner)->create();
        $client = Client::factory()->for($org, 'organization')->create();
        $account = SocialAccount::factory()->for($client)->create([
            'org_id' => $org->id,
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-ig-1',
            'meta_access_token' => 'meta-user-token',
            'meta_access_token_expires_at' => now()->addDays(60),
            'meta_instagram_user_id' => '17841400000000001',
        ]);
        $post = Post::factory()->for($client)->create(['org_id' => $org->id]);

        // Use the real MetaGraphMusicProvider — only the outbound Graph HTTP call is faked.
        $this->app->instance(
            \App\Contracts\MusicProvider::class,
            new MetaGraphMusicProvider(new UploadPostMusicProvider),
        );

        Http::fake([
            '*/ig_audio*' => Http::response([
                'data' => [
                    [
                        'audio_id' => 'meta-ig-track-9',
                        'title' => 'Meta Song',
                        'display_artist' => 'Meta Artist',
                        'download_url' => 'https://example.test/meta.mp3',
                    ],
                ],
            ], 200),
        ]);

        $this->actingAs($owner);

        $searchResponse = $this->getJson(route('posts.music-search', $post).'?query=vibes&platform=instagram');
        $searchResponse->assertOk();
        $selected = $searchResponse->json('tracks.0');
        $this->assertSame('meta-ig-track-9', $selected['id']);

        $updateResponse = $this->put(route('posts.update', $post), [
            'master_caption' => $post->master_caption ?? 'Caption',
            'music' => ['id' => $selected['id'], 'name' => $selected['name']],
            'targets' => [
                ['social_account_id' => $account->id],
            ],
        ]);
        $updateResponse->assertRedirect(route('posts.edit', $post));

        $post->refresh();
        $this->assertSame('meta-ig-track-9', $post->music['id'] ?? null);

        Http::fake([
            '*/ig_audio*' => Http::response([], 200),
            '*/upload_text' => Http::response(['request_id' => 'up-ig-meta'], 200),
        ]);
        $post->load('targets', 'assets');

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            $fields = $request->data();

            return $request->url() === 'https://api.upload-post.com/api/upload_text'
                && ($fields['audio_id'] ?? null) === 'meta-ig-track-9';
        });
    }
}
