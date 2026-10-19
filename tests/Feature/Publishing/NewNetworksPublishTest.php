<?php

namespace Tests\Feature\Publishing;

use App\Enums\AssetType;
use App\Enums\Platform;
use App\Enums\PlatformRolloutStage;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Publishing\UploadPostAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step I-3.4 / M-2.6 — YouTube Shorts, Google Business Profile, Threads, Pinterest and X: each
 * publishes with its documented Upload-Post fields (faked vendor), sits behind its own rollout
 * flag, and is only offered once enabled.
 */
class NewNetworksPublishTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['upload_post_key' => 'key']);
        $this->client = Client::factory()->for($this->org, 'organization')->create();
    }

    private function publish(Platform $platform, AssetType $media, array $accountAttributes = [], array $postAttributes = []): array
    {
        $account = SocialAccount::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'platform' => $platform,
            'external_account_id' => 'profile-1',
            ...$accountAttributes,
        ]);
        $post = Post::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'master_caption' => "Spring menu is live\nCome and try it.",
            'hashtags' => ['spring'],
            ...$postAttributes,
        ]);
        $post->assets()->attach(Asset::factory()->for($this->client)->create([
            'org_id' => $this->org->id,
            'type' => $media,
            'disk' => null,
            'path' => null,
            'url' => 'https://cdn.test/media.'.($media === AssetType::Video ? 'mp4' : 'jpg'),
            'width' => 1080,
            'height' => 1920,
            'duration_ms' => 20_000,
        ]));
        $post->targets()->create(['social_account_id' => $account->id]);

        $results = (new UploadPostAdapter)->publish($post->load('targets.socialAccount', 'assets'), collect([$account]));

        $sent = [];

        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            foreach ($request->data() as $part) {
                $sent[$part['name']][] = (string) $part['contents'];
            }
        }

        return [$results->first(), $sent];
    }

    public function test_youtube_shorts_publish_with_a_short_title_and_public_privacy(): void
    {
        Http::fake(['*/upload' => Http::response(['request_id' => 'yt-1'])]);

        [$result, $fields] = $this->publish(Platform::YouTube, AssetType::Video);

        $this->assertTrue($result->ok);
        $this->assertSame(['youtube'], $fields['platform[]']);
        $this->assertSame(['Spring menu is live'], $fields['youtube_title']);
        $this->assertSame(["Spring menu is live\nCome and try it.\n\n#spring"], $fields['description']);
        $this->assertSame(['public'], $fields['privacyStatus']);
    }

    public function test_google_business_publishes_an_update_with_a_learn_more_button_and_no_hashtags(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'gbp-1'])]);

        [$result, $fields] = $this->publish(Platform::GoogleBusiness, AssetType::Image,
            ['platform_settings' => ['gbp_location_id' => 'loc-7']],
            ['link_url' => 'https://alima.test/menu'],
        );

        $this->assertTrue($result->ok);
        $this->assertSame(['google_business'], $fields['platform[]']);
        $this->assertSame(["Spring menu is live\nCome and try it."], $fields['gbp_title'], 'Google doesn’t use hashtags.');
        $this->assertSame(['STANDARD'], $fields['gbp_topic_type']);
        $this->assertSame(['LEARN_MORE'], $fields['gbp_cta_type']);
        $this->assertSame(['https://alima.test/menu'], $fields['gbp_cta_url']);
        $this->assertSame(['loc-7'], $fields['gbp_location_id']);
        $this->assertArrayNotHasKey('hashtags[]', $fields);
    }

    public function test_threads_publishes_its_own_title_and_first_comment(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'th-1'])]);

        [$result, $fields] = $this->publish(Platform::Threads, AssetType::Image, postAttributes: ['first_comment' => 'Book now']);

        $this->assertTrue($result->ok);
        $this->assertSame(['threads'], $fields['platform[]']);
        $this->assertSame(["Spring menu is live\nCome and try it.\n\n#spring"], $fields['threads_title']);
        $this->assertSame(['Book now'], $fields['threads_first_comment']);
        $this->assertArrayNotHasKey('description', $fields);
    }

    public function test_pinterest_publishes_to_the_accounts_board(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'pin-1'])]);

        [$result, $fields] = $this->publish(Platform::Pinterest, AssetType::Image, ['platform_settings' => ['pinterest_board_id' => 'board-42']]);

        $this->assertTrue($result->ok);
        $this->assertSame(['pinterest'], $fields['platform[]']);
        $this->assertSame(['board-42'], $fields['pinterest_board_id']);
        $this->assertSame(['Spring menu is live'], $fields['pinterest_title']);
    }

    public function test_x_publishes_its_own_title_without_the_fold_spacer(): void
    {
        Http::fake(['*/upload_photos' => Http::response(['request_id' => 'x-1'])]);

        [$result, $fields] = $this->publish(Platform::X, AssetType::Image);

        $this->assertTrue($result->ok);
        $this->assertSame(['x'], $fields['platform[]']);
        $this->assertSame(["Spring menu is live\nCome and try it.\n\n#spring"], $fields['x_title']);
        $this->assertArrayNotHasKey('description', $fields);
    }

    public function test_each_new_network_has_its_own_rollout_flag_that_stops_publishing(): void
    {
        Http::fake();

        foreach ([PlatformRolloutStage::YouTubeShorts, PlatformRolloutStage::GoogleBusiness, PlatformRolloutStage::Threads, PlatformRolloutStage::Pinterest, PlatformRolloutStage::X] as $stage) {
            $this->assertTrue((bool) config("publishing.rollout.{$stage->value}"), "{$stage->value} defaults on");
        }

        config(['publishing.rollout.'.PlatformRolloutStage::Threads->value => false]);
        [$result] = $this->publish(Platform::Threads, AssetType::Image);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('Threads is not yet enabled', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_a_disabled_network_is_not_offered_for_new_accounts_or_in_the_editor(): void
    {
        config(['publishing.rollout.'.PlatformRolloutStage::X->value => false]);
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->post(route('clients.social-accounts.store', $this->client), [
            'platform' => 'x', 'handle' => '@alima', 'timezone' => 'Europe/London',
        ])->assertSessionHasErrors('platform');

        $x = SocialAccount::factory()->for($this->client)->create(['org_id' => $this->org->id, 'platform' => Platform::X]);
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id]);

        $this->actingAs($owner)->get(route('posts.edit', $post))
            ->assertInertia(fn ($page) => $page
                ->where('targetAccounts', fn ($accounts) => collect($accounts)->doesntContain('id', $x->id))
                ->where('platforms.x.enabled', false));
    }

    public function test_account_settings_only_accept_what_the_platform_uses(): void
    {
        $owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();

        $this->actingAs($owner)->post(route('clients.social-accounts.store', $this->client), [
            'platform' => 'pinterest', 'handle' => '@alima', 'timezone' => 'Europe/London',
            'platform_settings' => ['pinterest_board_id' => 'board-1', 'gbp_location_id' => 'nope'],
        ])->assertSessionHasErrors('platform_settings.gbp_location_id');

        $this->actingAs($owner)->post(route('clients.social-accounts.store', $this->client), [
            'platform' => 'pinterest', 'handle' => '@alima', 'timezone' => 'Europe/London',
            'platform_settings' => ['pinterest_board_id' => 'board-1'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['pinterest_board_id' => 'board-1'], SocialAccount::query()->where('platform', 'pinterest')->firstOrFail()->platform_settings);
    }
}
