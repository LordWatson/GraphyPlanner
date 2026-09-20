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

    /**
     * Upload-Post's `/upload`/`/upload_photos` endpoints require multipart/form-data (spec §7
     * bugfix), so the faked request body is a list of `['name' => ..., 'contents' => ...]` parts
     * rather than a plain associative array — this collects them back into `name => [values]` so
     * assertions read naturally regardless of how many times a name (e.g. `photos[]`) repeats.
     *
     * @return array<string, array<int, string>>
     */
    private static function multipartFieldsByName($request): array
    {
        $fields = [];

        foreach ($request->data() as $part) {
            $fields[$part['name']][] = $part['contents'];
        }

        return $fields;
    }

    public function test_linkedin_publish_sends_only_caption_media_and_schedule(): void
    {
        Http::fake([
            '*/upload_photos' => Http::response(['request_id' => 'up-123'], 200),
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
            $fields = self::multipartFieldsByName($request);

            return $request->url() === 'https://api.upload-post.com/api/upload_photos'
                && $request->hasHeader('Authorization', 'Apikey org-secret-key')
                && $request->isMultipart()
                && $fields['user'] === ['ext-acct-1']
                && $fields['title'] === ['Hello world']
                && $fields['platform[]'] === [Platform::LinkedIn->value]
                && $fields['photos[]'] === ['https://cdn.test/media.jpg']
                && $fields['hashtags[]'] === ['graphy']
                && $fields['scheduled_date'] === ['2026-10-01T12:00:00+00:00']
                && ! array_key_exists('location_id', $fields)
                && ! array_key_exists('tiktok_music_id', $fields);
        });

        $result = $results->first();
        $this->assertTrue($result->ok);
        $this->assertSame($account->id, $result->accountId);
        $this->assertSame('up-123', $result->externalPostId);
        $this->assertContains('photos', $result->sentFields);
        $this->assertContains('scheduled_date', $result->sentFields);
    }

    public function test_instagram_publish_maps_location_media_type_and_audio(): void
    {
        Http::fake([
            '*/upload' => Http::response(['request_id' => 'up-ig'], 200),
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
            $fields = self::multipartFieldsByName($request);

            return $request->isMultipart()
                && $fields['location_id'] === ['loc-42']
                && $fields['media_type'] === ['REELS']
                && $fields['audio_name'] === ['Some Song']
                && ! array_key_exists('audio_id', $fields);
        });
    }

    /**
     * Regression test: a photo asset stored on our own `public` disk previously had its `url`
     * sent to Upload-Post as a plain field, which is unreachable when APP_URL is a local-only dev
     * domain (e.g. `*.test`) — the vendor rejected the request with a generic "Photo files or
     * URLs are required" error. The file's actual bytes must be attached as a multipart file part
     * instead, and the (redundant/unreachable) `photos` URL field must not also be sent.
     */
    public function test_a_locally_stored_photo_asset_is_attached_as_a_file_instead_of_a_url(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('assets/photo.jpg', 'fake-image-bytes');

        Http::fake([
            '*/upload_photos' => Http::response(['request_id' => 'up-local'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::LinkedIn,
        ]);
        $post = Post::factory()->create(['client_id' => $account->client_id]);
        $asset = Asset::factory()->uploaded()->create([
            'client_id' => $post->client_id,
            'type' => AssetType::Image,
            'path' => 'assets/photo.jpg',
            'url' => 'http://graphy-planner.test/storage/assets/photo.jpg',
        ]);
        $post->assets()->attach($asset);
        $post->load('assets');

        $results = (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) {
            $fields = self::multipartFieldsByName($request);

            return $request->isMultipart()
                && $fields['photos[]'] === ['fake-image-bytes']
                && ! array_key_exists('photos', $fields);
        });

        $this->assertTrue($results->first()->ok);
    }

    public function test_tiktok_publish_maps_music_id_and_location_and_skips_ai_flag(): void
    {
        Http::fake([
            '*/upload_text' => Http::response(['request_id' => 'up-tt'], 200),
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
            '*/upload_text' => Http::response(['error' => 'Invalid caption'], 422),
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

    public function test_check_status_returns_a_resolved_target_result_using_the_matching_platform_result(): void
    {
        Http::fake([
            '*/uploadposts/status*' => Http::response([
                'job_id' => 'job-123',
                'status' => 'completed',
                'results' => [
                    ['platform' => 'linkedin', 'success' => true, 'message' => 'Published'],
                ],
            ], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create(['platform' => Platform::LinkedIn]);

        $result = (new UploadPostAdapter)->checkStatus($account, 'job-123');

        $this->assertNotNull($result);
        $this->assertTrue($result->ok);
        $this->assertSame('job-123', $result->externalPostId);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.upload-post.com/api/uploadposts/status')
            && $request['job_id'] === 'job-123'
            && $request['request_id'] === 'job-123'
            && $request->hasHeader('Authorization', 'Apikey org-secret-key'));
    }

    public function test_check_status_returns_a_failed_target_result_when_the_matching_platform_result_failed(): void
    {
        Http::fake([
            '*/uploadposts/status*' => Http::response([
                'status' => 'completed',
                'results' => [
                    ['platform' => 'linkedin', 'success' => false, 'message' => 'Rejected by platform'],
                ],
            ], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create(['platform' => Platform::LinkedIn]);

        $result = (new UploadPostAdapter)->checkStatus($account, 'job-123');

        $this->assertNotNull($result);
        $this->assertFalse($result->ok);
        $this->assertSame('Rejected by platform', $result->error);
    }

    public function test_check_status_falls_back_to_the_top_level_status_when_no_platform_result_matches(): void
    {
        Http::fake([
            '*/uploadposts/status*' => Http::response(['status' => 'failed', 'message' => 'Upload appears to have failed'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create(['platform' => Platform::LinkedIn]);

        $result = (new UploadPostAdapter)->checkStatus($account, 'job-123');

        $this->assertNotNull($result);
        $this->assertFalse($result->ok);
        $this->assertSame('Upload appears to have failed', $result->error);
    }

    public function test_check_status_returns_null_while_still_processing(): void
    {
        Http::fake([
            '*/uploadposts/status*' => Http::response(['status' => 'processing'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create();

        $this->assertNull((new UploadPostAdapter)->checkStatus($account, 'job-123'));
    }

    public function test_check_status_returns_null_when_the_vendor_reports_not_found(): void
    {
        Http::fake([
            '*/uploadposts/status*' => Http::response(['status' => 'not_found'], 200),
        ]);

        $organization = Organization::factory()->create(['upload_post_key' => 'org-secret-key']);
        $account = SocialAccount::factory()->for($organization, 'organization')->create();

        $this->assertNull((new UploadPostAdapter)->checkStatus($account, 'job-123'));
    }
}
