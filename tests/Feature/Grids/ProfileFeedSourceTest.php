<?php

namespace Tests\Feature\Grids;

use App\Contracts\ProfileFeedSource;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Grids\CompositeProfileFeedSource;
use App\Support\Grids\ProfileFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsGridPlans;
use Tests\TestCase;

/**
 * Step 18.3 — the live profile feed: Graph API request shape and mapping, the cache and its
 * refresh, the app-posts fallback, and that the Meta token never reaches a log line.
 */
class ProfileFeedSourceTest extends TestCase
{
    use BuildsGridPlans, RefreshDatabase;

    private const TOKEN = 'EAAG-secret-meta-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGridOrg();
        config()->set('publishing.http.retries', 0);
        config()->set('publishing.http.retry_base_ms', 0);
    }

    public function test_it_reads_the_profile_and_media_with_the_meta_token_and_maps_thumbnails(): void
    {
        $account = $this->metaAccount();
        $this->fakeGraph();

        $feed = app(ProfileFeedSource::class)->recent($account);

        $this->assertSame(ProfileFeed::SOURCE_META, $feed->source);
        $this->assertSame('studio', $feed->username);
        $this->assertSame(128, $feed->mediaCount);
        $this->assertSame('https://cdn.example/pic.jpg', $feed->profilePictureUrl);

        // Stories are dropped; videos show their thumbnail; carousels their first image.
        $this->assertSame(['1', '2', '3'], array_map(fn ($item) => $item->id, $feed->items));
        $this->assertSame('https://cdn.example/1.jpg', $feed->items[0]->thumbnailUrl);
        $this->assertSame('https://cdn.example/2-first.jpg', $feed->items[1]->thumbnailUrl);
        $this->assertSame('https://cdn.example/3-thumb.jpg', $feed->items[2]->thumbnailUrl);
        $this->assertSame('VIDEO', $feed->items[2]->mediaType);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/v20.0/17841400000/media')
            && str_contains($request->url(), 'access_token='.self::TOKEN)
            && str_contains(urldecode($request->url()), 'thumbnail_url'));
        Http::assertSent(fn (Request $request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/17841400000')
            && str_contains(urldecode($request->url()), 'media_count'));
    }

    public function test_it_is_cached_per_account_and_refresh_busts_the_cache(): void
    {
        $account = $this->metaAccount();
        $this->fakeGraph();
        $source = app(ProfileFeedSource::class);

        $source->recent($account);
        $source->recent($account);
        Http::assertSentCount(2);
        $this->assertTrue(Cache::has("grid-feed:{$account->id}"));

        $this->assertTrue(app(CompositeProfileFeedSource::class)->refresh($account));
        $this->assertFalse(Cache::has("grid-feed:{$account->id}"));

        $source->recent($account);
        Http::assertSentCount(4);
    }

    public function test_refresh_is_rate_limited_per_account(): void
    {
        config()->set('grids.feed_refresh_per_hour', 2);
        $account = $this->metaAccount();
        $composite = app(CompositeProfileFeedSource::class);

        $this->assertTrue($composite->refresh($account));
        $this->assertTrue($composite->refresh($account));
        $this->assertFalse($composite->refresh($account));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failingStatuses(): array
    {
        return ['400' => [400], '401' => [401], '500' => [500], '503' => [503]];
    }

    #[DataProvider('failingStatuses')]
    public function test_a_graph_error_falls_back_to_app_posts_with_a_warning(int $status): void
    {
        Log::spy();
        $account = $this->metaAccount();
        $this->publishedPost($account);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'bad '.self::TOKEN]], $status)]);

        $feed = app(ProfileFeedSource::class)->recent($account);

        $this->assertSame(ProfileFeed::SOURCE_APP, $feed->source);
        $this->assertCount(1, $feed->items);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Instagram profile feed fell back to app posts'
            && $context['social_account_id'] === $account->id);
        $this->assertFalse(Cache::has("grid-feed:{$account->id}"));
    }

    public function test_a_timeout_falls_back_to_app_posts(): void
    {
        $account = $this->metaAccount();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://graph.facebook.com/?access_token='.self::TOKEN));

        $this->assertSame(ProfileFeed::SOURCE_APP, app(ProfileFeedSource::class)->recent($account)->source);
    }

    public function test_an_account_without_facebook_login_uses_app_posts_and_never_calls_meta(): void
    {
        Http::fake();
        $this->publishedPost($this->instagram);

        // A failed post and another account's post don't show.
        $failed = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Failed]);
        $failed->targets()->create(['social_account_id' => $this->instagram->id, 'status' => PostTargetStatus::Failed, 'scheduled_at_utc' => now()->subDay()]);

        $feed = app(ProfileFeedSource::class)->recent($this->instagram);

        $this->assertSame(ProfileFeed::SOURCE_APP, $feed->source);
        $this->assertSame('studio', $feed->username);
        $this->assertCount(1, $feed->items);
        Http::assertNothingSent();
    }

    public function test_an_expired_meta_token_is_not_used(): void
    {
        Http::fake();
        $account = $this->metaAccount();
        $account->update(['meta_access_token_expires_at' => now()->subDay()]);

        $this->assertSame(ProfileFeed::SOURCE_APP, app(ProfileFeedSource::class)->recent($account)->source);
        Http::assertNothingSent();
    }

    public function test_the_token_and_media_urls_are_never_logged(): void
    {
        $lines = [];
        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->message.' '.json_encode($event->context);
        });

        $account = $this->metaAccount();
        $this->fakeGraph();
        app(ProfileFeedSource::class)->recent($account);

        Cache::flush();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token '.self::TOKEN]], 401)]);
        app(ProfileFeedSource::class)->recent($account);

        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('timed out https://graph.facebook.com/?access_token='.self::TOKEN));
        app(ProfileFeedSource::class)->recent($account);

        $this->assertNotEmpty($lines);

        foreach ($lines as $line) {
            $this->assertStringNotContainsString(self::TOKEN, $line);
            $this->assertStringNotContainsString('cdn.example', $line);
        }
    }

    private function metaAccount(): SocialAccount
    {
        $this->instagram->update([
            'meta_access_token' => self::TOKEN,
            'meta_access_token_expires_at' => now()->addDays(30),
            'meta_instagram_user_id' => '17841400000',
        ]);

        return $this->instagram->refresh();
    }

    private function fakeGraph(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/17841400000/media*' => Http::response(['data' => [
                ['id' => '1', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'media_url' => 'https://cdn.example/1.jpg', 'permalink' => 'https://instagram.com/p/1', 'timestamp' => '2026-10-19T09:00:00+0000'],
                ['id' => '2', 'media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'FEED', 'media_url' => 'https://cdn.example/2-first.jpg', 'timestamp' => '2026-10-18T09:00:00+0000'],
                ['id' => '3', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'media_url' => 'https://cdn.example/3.mp4', 'thumbnail_url' => 'https://cdn.example/3-thumb.jpg', 'timestamp' => '2026-10-17T09:00:00+0000'],
                ['id' => '4', 'media_type' => 'IMAGE', 'media_product_type' => 'STORY', 'media_url' => 'https://cdn.example/4.jpg'],
            ]]),
            'graph.facebook.com/v20.0/17841400000*' => Http::response([
                'username' => 'studio',
                'profile_picture_url' => 'https://cdn.example/pic.jpg',
                'media_count' => 128,
            ]),
        ]);
    }

    private function publishedPost(SocialAccount $account): Post
    {
        $post = Post::factory()->for($this->client)->create(['org_id' => $this->org->id, 'status' => PostStatus::Published]);
        $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => PostTargetStatus::Published,
            'scheduled_at_utc' => now()->subDay(),
        ]);
        $post->assets()->attach($this->image()->id, ['sort_order' => 0]);

        return $post;
    }
}
