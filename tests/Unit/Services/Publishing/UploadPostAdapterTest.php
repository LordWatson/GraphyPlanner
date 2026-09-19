<?php

namespace Tests\Unit\Services\Publishing;

use App\Contracts\PublishAdapter;
use App\Enums\AssetType;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Publishing\UploadPostAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class UploadPostAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_satisfies_the_publish_adapter_contract(): void
    {
        $this->assertInstanceOf(PublishAdapter::class, new UploadPostAdapter);
    }

    public function test_it_is_bound_as_the_default_publish_adapter(): void
    {
        $this->assertInstanceOf(UploadPostAdapter::class, app(PublishAdapter::class));
    }

    public function test_linkedin_publish_sends_only_caption_media_and_schedule(): void
    {
        Http::fake([
            '*/uploadposts/schedule' => Http::response(['request_id' => 'up-123'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::LinkedIn,
            'external_account_id' => 'ext-acct-1',
        ]);
        $post = Post::factory()->create([
            'client_id' => $account->client_id,
            'master_caption' => 'Hello world',
            // Post::booted() normalizes any leading "#" on save, so this is stored/sent as 'graphy'.
            'hashtags' => ['#graphy'],
        ]);
        $asset = Asset::factory()->create(['client_id' => $post->client_id, 'url' => 'https://cdn.test/media.jpg']);
        $post->assets()->attach($asset);
        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'scheduled_at_utc' => '2026-10-01 12:00:00',
        ]);
        $post->load('targets', 'assets');

        $results = (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.upload-post.com/api/uploadposts/schedule'
                && $request->hasHeader('Authorization', 'Apikey org-secret-key')
                && $request['user'] === 'ext-acct-1'
                && $request['title'] === 'Hello world'
                && $request['platform'] === [Platform::LinkedIn->value]
                && $request['media_urls'] === ['https://cdn.test/media.jpg']
                && $request['hashtags'] === ['graphy']
                && $request['scheduled_date'] === '2026-10-01T12:00:00+00:00'
                && ! array_key_exists('location_id', $request->data())
                && ! array_key_exists('tiktok_music_id', $request->data());
        });

        $result = $results->first();
        $this->assertTrue($result->ok);
        $this->assertSame($account->id, $result->accountId);
        $this->assertSame('up-123', $result->externalPostId);
        $this->assertContains('media_urls', $result->sentFields);
        $this->assertContains('scheduled_date', $result->sentFields);
    }

    public function test_instagram_publish_maps_location_media_type_and_audio(): void
    {
        Http::fake([
            '*/uploadposts/schedule' => Http::response(['request_id' => 'up-ig'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::Instagram,
        ]);
        $post = Post::factory()->create([
            'client_id' => $account->client_id,
            'location' => ['id' => 'loc-42', 'name' => 'Studio'],
            'music' => ['name' => 'Some Song'],
        ]);
        $asset = Asset::factory()->create([
            'client_id' => $post->client_id,
            'type' => AssetType::Video,
            'url' => 'https://cdn.test/reel.mp4',
        ]);
        $post->assets()->attach($asset);
        $post->load('assets');

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['location_id'] === 'loc-42'
                && $data['media_type'] === 'REELS'
                && $data['audio_name'] === 'Some Song'
                && ! array_key_exists('audio_id', $data);
        });
    }

    public function test_tiktok_publish_maps_music_id_and_location_and_skips_ai_flag(): void
    {
        Http::fake([
            '*/uploadposts/schedule' => Http::response(['request_id' => 'up-tt'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::TikTok,
        ]);
        $post = Post::factory()->create([
            'client_id' => $account->client_id,
            'music' => ['id' => 'music-9'],
            'location' => ['name' => 'Berlin'],
        ]);
        $post->load('assets');

        $results = (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['tiktok_music_id'] === 'music-9'
                && $data['location'] === 'Berlin';
        });

        $this->assertContains('tiktok_is_ai_generated', $results->first()->skippedFields);
    }

    public function test_connect_account_returns_the_vendor_access_url(): void
    {
        Http::fake([
            '*/uploadposts/users/generate-jwt' => Http::response(['access_url' => 'https://upload-post.test/connect/abc'], 200),
            '*/uploadposts/users' => Http::response(['success' => true], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'external_account_id' => 'ext-acct-1',
        ]);

        $url = (new UploadPostAdapter)->connectAccount($account);

        $this->assertSame('https://upload-post.test/connect/abc', $url);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.upload-post.com/api/uploadposts/users'
                && $request->hasHeader('Authorization', 'Apikey org-secret-key')
                && $request['username'] === 'ext-acct-1';
        });
        Http::assertSent(function ($request) use ($account) {
            return $request->url() === 'https://api.upload-post.com/api/uploadposts/users/generate-jwt'
                && $request->hasHeader('Authorization', 'Apikey org-secret-key')
                && $request['username'] === 'ext-acct-1'
                && str_contains((string) $request['redirect_url'], (string) $account->id);
        });
    }

    public function test_connect_account_creates_the_profile_when_it_does_not_exist_yet(): void
    {
        Http::fake([
            '*/uploadposts/users/generate-jwt' => Http::response(['access_url' => 'https://upload-post.test/connect/abc'], 200),
            '*/uploadposts/users' => Http::response(['success' => true, 'message' => 'Profile created'], 201),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'external_account_id' => 'ext-acct-1',
        ]);

        $url = (new UploadPostAdapter)->connectAccount($account);

        $this->assertSame('https://upload-post.test/connect/abc', $url);
    }

    public function test_connect_account_treats_an_already_existing_profile_as_success(): void
    {
        Http::fake([
            '*/uploadposts/users/generate-jwt' => Http::response(['access_url' => 'https://upload-post.test/connect/abc'], 200),
            '*/uploadposts/users' => Http::response(['success' => false, 'error' => 'Profile already exists', 'error_code' => 'PROFILE_ALREADY_EXISTS'], 409),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'external_account_id' => 'ext-acct-1',
        ]);

        $url = (new UploadPostAdapter)->connectAccount($account);

        $this->assertSame('https://upload-post.test/connect/abc', $url);
    }

    public function test_connect_account_throws_when_profile_creation_fails_for_another_reason(): void
    {
        Http::fake([
            '*/uploadposts/users' => Http::response(['success' => false, 'error' => 'Profile limit reached', 'error_code' => 'PROFILE_LIMIT_REACHED'], 422),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create();

        $this->expectException(RuntimeException::class);

        (new UploadPostAdapter)->connectAccount($account);
    }

    public function test_connect_account_throws_when_the_vendor_rejects_the_request(): void
    {
        Http::fake([
            '*/uploadposts/users' => Http::response(['success' => true], 200),
            '*/uploadposts/users/generate-jwt' => Http::response(['error' => 'Invalid key'], 401),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'bad-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create();

        $this->expectException(RuntimeException::class);

        (new UploadPostAdapter)->connectAccount($account);
    }

    public function test_handle_callback_persists_the_external_profile_id_and_connects_the_account(): void
    {
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::NotConnected]);

        (new UploadPostAdapter)->handleCallback([
            'social_account_id' => $account->id,
            'profile' => 'vendor-profile-123',
        ]);

        $account->refresh();
        $this->assertSame('vendor-profile-123', $account->external_profile_id);
        $this->assertSame(ConnectionStatus::Connected, $account->connection_status);
        $this->assertNotNull($account->connected_at);
    }

    public function test_handle_callback_is_a_no_op_when_the_social_account_id_is_missing(): void
    {
        $account = SocialAccount::factory()->create(['connection_status' => ConnectionStatus::NotConnected]);

        (new UploadPostAdapter)->handleCallback(['profile' => 'vendor-profile-123']);

        $account->refresh();
        $this->assertSame(ConnectionStatus::NotConnected, $account->connection_status);
    }

    public function test_publish_returns_a_failed_target_result_when_the_vendor_rejects_the_request(): void
    {
        Http::fake([
            '*/uploadposts/schedule' => Http::response(['error' => 'Invalid caption'], 422),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::Facebook,
        ]);
        $post = Post::factory()->create(['client_id' => $account->client_id]);
        $post->load('assets');

        $results = (new UploadPostAdapter)->publish($post, new Collection([$account]));

        $result = $results->first();
        $this->assertFalse($result->ok);
        $this->assertSame('Invalid caption', $result->error);
        $this->assertNull($result->externalPostId);
    }
}
