<?php

namespace Tests\Unit\Services\Publishing;

use App\Contracts\MusicProvider;
use App\Enums\ConnectionStatus;
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

class UploadPostMusicProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_satisfies_the_music_provider_contract(): void
    {
        $this->assertInstanceOf(MusicProvider::class, new UploadPostMusicProvider);
    }

    public function test_it_is_bound_as_the_delegate_music_provider_behind_meta_graph_music_provider(): void
    {
        // Step 1.9.4: the default MusicProvider binding is now MetaGraphMusicProvider, which
        // wraps this class for every platform other than Instagram (see
        // MetaGraphMusicProviderTest for that coverage).
        $this->assertInstanceOf(MetaGraphMusicProvider::class, app(MusicProvider::class));
    }

    private function actingOrgWithConnectedTikTok(): SocialAccount
    {
        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::TikTok,
            'connection_status' => ConnectionStatus::Connected,
            'external_account_id' => 'tiktok-profile-1',
        ]);

        $this->actingAs($owner);

        return $account;
    }

    public function test_search_returns_tiktok_tracks_from_the_vendor(): void
    {
        Http::fake([
            '*/uploadposts/tiktok/music/search*' => Http::response([
                'success' => true,
                'tracks' => [
                    [
                        'id' => '7363314838511175697',
                        'title' => 'Ok I Like It',
                        'artist' => 'Milky Chance',
                        'preview_url' => 'https://cdn.test/preview.mp3',
                    ],
                ],
            ], 200),
        ]);

        $this->actingOrgWithConnectedTikTok();

        $results = (new UploadPostMusicProvider)->search('milky chance', Platform::TikTok);

        $this->assertCount(1, $results);
        /** @var MusicTrack $track */
        $track = $results->first();
        $this->assertSame('7363314838511175697', $track->id);
        $this->assertSame('Ok I Like It', $track->name);
        $this->assertSame('Milky Chance', $track->artist);
        $this->assertSame(Platform::TikTok, $track->platform);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.upload-post.com/api/uploadposts/tiktok/music/search')
                && $request->hasHeader('Authorization', 'Apikey org-secret-key')
                && $request['profile'] === 'tiktok-profile-1'
                && $request['q'] === 'milky chance';
        });
    }

    public function test_search_returns_an_empty_collection_for_unsupported_platforms(): void
    {
        Http::fake();

        $this->actingOrgWithConnectedTikTok();

        $results = (new UploadPostMusicProvider)->search('lofi', Platform::Instagram);

        $this->assertCount(0, $results);
        Http::assertNothingSent();
    }

    public function test_search_throws_when_no_tiktok_account_is_connected(): void
    {
        Http::fake();

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $owner = User::factory()->for($organization, 'organization')->role(Role::Owner)->create();
        $this->actingAs($owner);

        $this->expectException(MusicProviderUnavailableException::class);

        try {
            (new UploadPostMusicProvider)->search('lofi', Platform::TikTok);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_find_returns_the_matching_track_by_id(): void
    {
        Http::fake([
            '*/uploadposts/tiktok/music/search*' => Http::response([
                'success' => true,
                'tracks' => [
                    ['id' => 'track-1', 'title' => 'First'],
                    ['id' => 'track-2', 'title' => 'Second'],
                ],
            ], 200),
        ]);

        $this->actingOrgWithConnectedTikTok();

        $track = (new UploadPostMusicProvider)->find('track-2', Platform::TikTok);

        $this->assertNotNull($track);
        $this->assertSame('Second', $track->name);
    }

    public function test_find_returns_null_when_no_track_matches(): void
    {
        Http::fake([
            '*/uploadposts/tiktok/music/search*' => Http::response([
                'success' => true,
                'tracks' => [['id' => 'track-1', 'title' => 'First']],
            ], 200),
        ]);

        $this->actingOrgWithConnectedTikTok();

        $this->assertNull((new UploadPostMusicProvider)->find('missing', Platform::TikTok));
    }

    public function test_find_returns_null_for_unsupported_platforms(): void
    {
        Http::fake();

        $this->actingOrgWithConnectedTikTok();

        $this->assertNull((new UploadPostMusicProvider)->find('anything', Platform::Instagram));
        Http::assertNothingSent();
    }

    public function test_search_throws_when_the_vendor_rejects_the_request(): void
    {
        Http::fake([
            '*/uploadposts/tiktok/music/search*' => Http::response(['success' => false, 'message' => 'Invalid key'], 401),
        ]);

        $this->actingOrgWithConnectedTikTok();

        $this->expectException(MusicProviderUnavailableException::class);
        $this->expectExceptionMessage('Invalid key');

        (new UploadPostMusicProvider)->search('lofi', Platform::TikTok);
    }

    public function test_search_throws_a_reconnect_message_when_the_vendor_reports_tiktok_needs_reauth(): void
    {
        Http::fake([
            '*/uploadposts/tiktok/music/search*' => Http::response([
                'success' => false,
                'message' => "Profile 'x' has no TikTok connection that supports this endpoint.",
                'error_code' => 'tiktok_reconnect_required',
            ], 400),
        ]);

        $this->actingOrgWithConnectedTikTok();

        $this->expectException(MusicProviderUnavailableException::class);
        $this->expectExceptionMessage('Your TikTok connection needs to be re-authenticated before you can search for music.');

        (new UploadPostMusicProvider)->search('lofi', Platform::TikTok);
    }
}
