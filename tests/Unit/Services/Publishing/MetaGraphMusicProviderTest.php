<?php

namespace Tests\Unit\Services\Publishing;

use App\Contracts\MusicProvider;
use App\Enums\Platform;
use App\Enums\Role;
use App\Exceptions\Publishing\MusicProviderUnavailableException;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\MetaGraphMusicProvider;
use App\Services\Publishing\UploadPostMusicProvider;
use App\Support\Publishing\MusicTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaGraphMusicProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_satisfies_the_music_provider_contract(): void
    {
        $this->assertInstanceOf(MusicProvider::class, new MetaGraphMusicProvider(new UploadPostMusicProvider));
    }

    private function actingOrgWithConnectedInstagramMetaAccess(): SocialAccount
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $account = SocialAccount::factory()->for($organization, 'organization')->metaConnected()->create();

        $this->actingAs($owner);

        return $account;
    }

    public function test_search_returns_real_instagram_results_from_the_graph_api(): void
    {
        Http::fake([
            '*/ig_audio*' => Http::response([
                'data' => [
                    [
                        'audio_id' => '17895695668004550',
                        'title' => 'Summer Vibes',
                        'display_artist' => 'DJ Test',
                        'download_url' => 'https://cdn.test/track.mp3',
                    ],
                ],
            ], 200),
        ]);

        $account = $this->actingOrgWithConnectedInstagramMetaAccess();

        $results = (new MetaGraphMusicProvider(new UploadPostMusicProvider))->search('summer', Platform::Instagram);

        $this->assertCount(1, $results);
        /** @var MusicTrack $track */
        $track = $results->first();
        $this->assertSame('17895695668004550', $track->id);
        $this->assertSame('Summer Vibes', $track->name);
        $this->assertSame('DJ Test', $track->artist);
        $this->assertSame(Platform::Instagram, $track->platform);

        Http::assertSent(function ($request) use ($account) {
            return str_contains($request->url(), '/ig_audio')
                && $request['user_id'] === $account->meta_instagram_user_id
                && $request['search_query'] === 'summer'
                && str_contains($request->url(), 'access_token='.$account->meta_access_token);
        });
    }

    public function test_search_delegates_tiktok_unchanged_to_upload_post_music_provider(): void
    {
        Http::fake([
            '*/uploadposts/tiktok/music/search*' => Http::response([
                'success' => true,
                'tracks' => [['id' => 'track-1', 'title' => 'TikTok Track']],
            ], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::TikTok,
            'connection_status' => \App\Enums\ConnectionStatus::Connected,
            'external_account_id' => 'tiktok-profile-1',
        ]);
        $this->actingAs($owner);

        $results = (new MetaGraphMusicProvider(new UploadPostMusicProvider))->search('lofi', Platform::TikTok);

        $this->assertCount(1, $results);
        $this->assertSame('TikTok Track', $results->first()->name);
    }

    public function test_search_throws_a_meta_not_connected_error_when_instagram_is_not_connected(): void
    {
        Http::fake();

        $organization = Organization::factory()->create();
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $this->actingAs($owner);

        try {
            (new MetaGraphMusicProvider(new UploadPostMusicProvider))->search('lofi', Platform::Instagram);
            $this->fail('Expected MusicProviderUnavailableException to be thrown.');
        } catch (MusicProviderUnavailableException $e) {
            $this->assertSame('meta_not_connected', $e->errorCode());
        }

        Http::assertNothingSent();
    }
}
