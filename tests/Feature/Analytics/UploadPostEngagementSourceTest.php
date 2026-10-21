<?php

namespace Tests\Feature\Analytics;

use App\Enums\Platform;
use App\Exceptions\Analytics\EngagementSourceException;
use App\Services\Analytics\Engagement\UploadPostEngagementSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step M-3.1 — the Upload-Post engagement source against faked vendor responses shaped like
 * docs.upload-post.com/api/get-analytics.
 */
class UploadPostEngagementSourceTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.upload_post.base_url' => 'https://upload-post.test/api', 'publishing.http.retries' => 0]);
    }

    public function test_post_metrics_are_read_by_request_id_and_mapped(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::Instagram);
        $post = $this->publishedPost($account->client, [$account], now()->subDay());
        $target = $post->targets()->firstOrFail();
        $target->update(['vendor_request_id' => 'req-1']);

        Http::fake([
            'upload-post.test/api/uploadposts/post-analytics/req-1*' => Http::response([
                'success' => true,
                'platforms' => [
                    'instagram' => [
                        'success' => true,
                        'platform_post_id' => '1798',
                        'post_url' => 'https://www.instagram.com/p/abc/',
                        'post_metrics' => ['likes' => 40, 'comments' => 5, 'shares' => 3, 'saves' => 2, 'reach' => 900, 'views' => 1200],
                    ],
                ],
            ]),
        ]);

        $reading = (new UploadPostEngagementSource)->postMetrics($target->fresh());

        $this->assertNotNull($reading);
        $this->assertSame(50, $reading->engagement());
        $this->assertSame(900, $reading->reach);
        $this->assertNull($reading->impressions);
        $this->assertSame('1798', $reading->platformPostId);
        $this->assertSame('https://www.instagram.com/p/abc/', $reading->postUrl);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Apikey up-key')
            && str_contains($request->url(), 'platform=instagram'));
    }

    public function test_post_metrics_fall_back_to_the_live_endpoint_by_platform_post_id(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::X);
        $post = $this->publishedPost($account->client, [$account], now()->subDay());
        $target = $post->targets()->firstOrFail();
        $target->update(['platform_post_id' => 'tweet-9']);

        Http::fake([
            'upload-post.test/api/uploadposts/post-analytics?*' => Http::response([
                'success' => true,
                'platforms' => ['x' => ['success' => true, 'post_metrics' => ['likes' => 7, 'replies' => 2, 'retweets' => 1, 'impressions' => 300]]],
            ]),
        ]);

        $reading = (new UploadPostEngagementSource)->postMetrics($target->fresh());

        $this->assertSame(10, $reading?->engagement());
        $this->assertSame(2, $reading?->comments);
        $this->assertSame(1, $reading?->shares);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'platform_post_id=tweet-9')
            && str_contains($request->url(), 'user=profile-'));
    }

    public function test_google_business_posts_are_never_requested(): void
    {
        Http::fake();
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::GoogleBusiness);
        $post = $this->publishedPost($account->client, [$account], now()->subDay());

        $this->assertNull((new UploadPostEngagementSource)->postMetrics($post->targets()->firstOrFail()));
        Http::assertNothingSent();
    }

    public function test_a_vendor_error_throws_a_safe_message(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::Instagram);
        $post = $this->publishedPost($account->client, [$account], now()->subDay());

        Http::fake(['upload-post.test/*' => Http::response(['message' => 'Unauthorized'], 401)]);

        try {
            (new UploadPostEngagementSource)->postMetrics($post->targets()->firstOrFail());
            $this->fail('Expected an EngagementSourceException.');
        } catch (EngagementSourceException $e) {
            $this->assertStringContainsString('Check the organization API key', $e->getMessage());
            $this->assertStringNotContainsString('up-key', $e->getMessage());
        }
    }

    public function test_a_platform_reported_metrics_error_throws(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::TikTok);
        $post = $this->publishedPost($account->client, [$account], now()->subDay());

        Http::fake(['upload-post.test/*' => Http::response([
            'success' => true,
            'platforms' => ['tiktok' => ['success' => false, 'post_metrics_error' => 'Reconnect TikTok to read insights.']],
        ])]);

        $this->expectExceptionObject(new EngagementSourceException('Reconnect TikTok to read insights.'));

        (new UploadPostEngagementSource)->postMetrics($post->targets()->firstOrFail());
    }

    public function test_account_metrics_parse_followers_and_the_daily_series(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::Facebook);
        $account->update(['platform_settings' => ['facebook_page_id' => 'page-55']]);

        Http::fake(['upload-post.test/api/analytics/*' => Http::response([
            'facebook' => [
                'followers' => 1520,
                'profileViews' => 33,
                'reach_timeseries' => [['date' => '2026-10-19', 'value' => 400], ['date' => '2026-10-20', 'value' => 410.4]],
                'impressions_timeseries' => [['date' => '2026-10-20', 'value' => 800], ['date' => 'bad', 'value' => 1]],
            ],
        ])]);

        $reading = (new UploadPostEngagementSource)->accountMetrics($account->fresh());

        $this->assertSame(1520, $reading?->followers);
        $this->assertSame(33, $reading?->profileViews);
        $this->assertSame([
            '2026-10-19' => ['reach' => 400, 'impressions' => null],
            '2026-10-20' => ['reach' => 410, 'impressions' => 800],
        ], $reading?->daily);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'platforms=facebook')
            && str_contains($request->url(), 'page_id=page-55'));
    }

    public function test_a_platform_without_analytics_reports_nothing(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::LinkedIn);

        Http::fake(['upload-post.test/*' => Http::response(['linkedin' => ['message' => 'Personal profiles are not supported.']])]);

        $this->assertNull((new UploadPostEngagementSource)->accountMetrics($account));
    }
}
