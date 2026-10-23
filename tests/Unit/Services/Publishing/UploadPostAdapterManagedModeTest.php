<?php

namespace Tests\Unit\Services\Publishing;

use App\Enums\OrganizationType;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\Publishing\UploadPostAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Step B-5.1 — a managed (business) org publishes with Alima's platform key and its own isolated
 * Upload-Post profile, never a per-org key, and the key never reaches the logs.
 */
class UploadPostAdapterManagedModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.upload_post.platform_key', 'PLATFORM-KEY-SECRET');
    }

    /**
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

    public function test_managed_org_publishes_with_the_platform_key_and_its_own_profile(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'up-managed-1'], 200)]);

        // A business org has no upload_post_key of its own.
        $organization = Organization::factory()->create([
            'type' => OrganizationType::Business,
            'upload_post_key' => null,
        ]);
        $account = SocialAccount::factory()->for($organization, 'organization')->create([
            'platform' => Platform::Instagram,
            'external_account_id' => 'ext-acct-should-not-be-used',
        ]);
        $post = Post::factory()->create([
            'client_id' => $account->client_id,
            'master_caption' => 'Fresh sourdough',
        ]);
        $asset = Asset::factory()->create(['client_id' => $post->client_id, 'url' => 'https://cdn.test/loaf.jpg']);
        $post->assets()->attach($asset);
        PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'scheduled_at_utc' => '2030-10-01 12:00:00',
        ]);
        $post->load('targets', 'assets');

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        Http::assertSent(function ($request) use ($organization) {
            $fields = self::multipartFieldsByName($request);

            return $request->hasHeader('Authorization', 'Apikey PLATFORM-KEY-SECRET')
                && $fields['user'][0] === $organization->fresh()->upload_post_profile
                && str_starts_with($fields['user'][0], "alima-org-{$organization->id}-")
                && $fields['user'][0] !== 'ext-acct-should-not-be-used';
        });
    }

    public function test_the_platform_key_never_appears_in_any_log_line(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'up-managed-2'], 200)]);

        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $organization = Organization::factory()->create(['type' => OrganizationType::Business, 'upload_post_key' => null]);
        $account = SocialAccount::factory()->for($organization, 'organization')->create(['platform' => Platform::Instagram]);
        $post = Post::factory()->create(['client_id' => $account->client_id, 'master_caption' => 'Hi']);
        $asset = Asset::factory()->create(['client_id' => $post->client_id, 'url' => 'https://cdn.test/a.jpg']);
        $post->assets()->attach($asset);
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'scheduled_at_utc' => '2030-10-01 12:00:00']);
        $post->load('targets', 'assets');

        (new UploadPostAdapter)->publish($post, new Collection([$account]));

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('PLATFORM-KEY-SECRET', $line);
        }
        $this->assertNotEmpty($logged, 'expected the publish path to log at least once');
    }
}
