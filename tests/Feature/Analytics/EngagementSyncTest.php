<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\SyncOrganizationEngagementAction;
use App\Actions\Analytics\SyncPostTargetMetricsAction;
use App\Actions\Analytics\SyncSocialAccountMetricsAction;
use App\Contracts\EngagementSource;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\SyncOrganizationEngagementJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\PostTargetMetric;
use App\Models\SocialAccount;
use App\Models\SocialAccountMetric;
use App\Services\Analytics\Engagement\NullEngagementSource;
use App\Support\Analytics\Engagement\AccountMetricsReading;
use App\Support\Analytics\Engagement\PostMetricsReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\Feature\Analytics\Concerns\FakeEngagementSource;
use Tests\TestCase;

/**
 * Step M-3.1 / Phase 12 Step 12.1 — storing readings: append-only history with a latest pointer,
 * daily account rows in the account's timezone, the org sweep's guards, and the webhook keeping
 * what the vendor says about the live post.
 */
class EngagementSyncTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    public function test_each_sync_appends_a_reading_and_points_the_channel_at_the_newest(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org));
        $post = $this->publishedPost($account->client, [$account], now()->subDays(2));
        $target = $post->targets()->firstOrFail();

        $this->fakeSource(posts: [
            new PostMetricsReading(reach: 100, likes: 10, comments: 1, platformPostId: 'ig-1', postUrl: 'https://instagram.com/p/1'),
            new PostMetricsReading(reach: 180, likes: 15, comments: 3),
        ]);

        $this->assertSame('stored', app(SyncPostTargetMetricsAction::class)($target));
        $this->assertSame('stored', app(SyncPostTargetMetricsAction::class)($target->fresh()));

        $this->assertSame(2, PostTargetMetric::query()->where('post_target_id', $target->id)->count());
        $target->refresh();
        $this->assertSame(18, $target->latestMetrics?->engagement);
        $this->assertSame(180, $target->latestMetrics?->reach);
        $this->assertSame($org->id, $target->latestMetrics?->org_id);
        $this->assertSame('ig-1', $target->platform_post_id);
        $this->assertSame('https://instagram.com/p/1', $target->post_url);
        $this->assertNotNull($target->metrics_synced_at);
    }

    public function test_the_null_source_is_a_safe_no_op(): void
    {
        $this->app->bind(EngagementSource::class, NullEngagementSource::class);
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org));
        $post = $this->publishedPost($account->client, [$account], now()->subDay());

        $this->assertSame('empty', app(SyncPostTargetMetricsAction::class)($post->targets()->firstOrFail()));
        $this->assertSame('empty', app(SyncSocialAccountMetricsAction::class)($account));
        $this->assertSame(0, PostTargetMetric::query()->count());
        $this->assertSame(0, SocialAccountMetric::query()->count());
    }

    public function test_a_vendor_failure_is_recorded_not_thrown(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org));
        $post = $this->publishedPost($account->client, [$account], now()->subDay());
        $target = $post->targets()->firstOrFail();

        $this->fakeSource(error: 'Upload-Post is rate-limiting analytics.');

        $this->assertSame('failed', app(SyncPostTargetMetricsAction::class)($target));
        $this->assertSame('Upload-Post is rate-limiting analytics.', $target->fresh()?->metrics_error);
        $this->assertSame('failed', app(SyncSocialAccountMetricsAction::class)($account));
        $this->assertSame('Upload-Post is rate-limiting analytics.', $account->fresh()?->metrics_error);
        $this->assertSame(0, PostTargetMetric::query()->count());
    }

    public function test_account_metrics_keep_one_row_per_day_in_the_accounts_timezone(): void
    {
        $this->travelTo('2026-10-20 22:30:00'); // already 21 Oct in Dubai
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org), Platform::Instagram, 'Asia/Dubai');
        $this->followersOn($account, '2026-10-19', 900);

        $this->fakeSource(accounts: [
            new AccountMetricsReading(followers: 1000, profileViews: 12, daily: [
                '2026-10-19' => ['reach' => 300, 'impressions' => null],
                '2026-10-21' => ['reach' => 50, 'impressions' => 70],
                '2026-10-22' => ['reach' => 1, 'impressions' => 1],
            ]),
            new AccountMetricsReading(followers: 1004),
        ]);

        app(SyncSocialAccountMetricsAction::class)($account);
        app(SyncSocialAccountMetricsAction::class)($account->fresh());

        $rows = SocialAccountMetric::query()->where('social_account_id', $account->id)->orderBy('metric_date')->get();

        $this->assertSame(['2026-10-19', '2026-10-21'], $rows->map(fn ($row) => $row->metric_date->toDateString())->all());
        // The daily series never overwrites a follower count already captured for that day.
        $this->assertSame(900, $rows[0]->followers);
        $this->assertSame(300, $rows[0]->reach);
        // Today's row ends with the latest count; the future date was ignored.
        $this->assertSame(1004, $rows[1]->followers);
        $this->assertSame(12, $rows[1]->profile_views);
        $this->assertSame(50, $rows[1]->reach);
    }

    public function test_the_org_sweep_reads_connected_accounts_and_recent_channels_only(): void
    {
        $org = $this->engagementOrg();
        $client = $this->engagementClient($org);
        $connected = $this->engagementAccount($client);
        $disconnected = SocialAccount::factory()->for($client)->create(['org_id' => $org->id, 'platform' => Platform::TikTok]);
        $recent = $this->publishedPost($client, [$connected], now()->subDays(3));
        $old = $this->publishedPost($client, [$connected], now()->subDays(120));
        $failedPost = Post::factory()->for($client)->create(['org_id' => $org->id, 'status' => PostStatus::Failed]);
        $failedPost->targets()->create(['social_account_id' => $connected->id, 'status' => PostTargetStatus::Failed]);

        $otherOrg = $this->engagementOrg();
        $otherAccount = $this->engagementAccount($this->engagementClient($otherOrg));
        $this->publishedPost($otherAccount->client, [$otherAccount], now()->subDay());

        $source = $this->fakeSource(posts: array_fill(0, 5, new PostMetricsReading(likes: 1)), accounts: array_fill(0, 5, new AccountMetricsReading(followers: 10)));

        $summary = app(SyncOrganizationEngagementAction::class)($org);

        $this->assertSame(['stored' => 1, 'empty' => 0, 'failed' => 0], $summary['accounts']);
        $this->assertSame(['stored' => 1, 'empty' => 0, 'failed' => 0], $summary['targets']);
        $this->assertSame([$recent->targets()->value('id')], $source->postCalls);
        $this->assertSame([$connected->id], $source->accountCalls);
        $this->assertNotContains($disconnected->id, $source->accountCalls);
        $this->assertNull($old->targets()->first()?->latest_metrics_id);

        // A second run within the interval reads nothing; --force reads again.
        app(SyncOrganizationEngagementAction::class)($org);
        $this->assertCount(1, $source->postCalls);
        app(SyncOrganizationEngagementAction::class)($org, force: true);
        $this->assertCount(2, $source->postCalls);
    }

    public function test_the_org_sweep_skips_suspended_orgs_and_orgs_without_a_vendor_key(): void
    {
        $suspended = $this->engagementOrg();
        $suspended->forceFill(['suspended_at' => now()])->save();
        $keyless = $this->engagementOrg();
        $keyless->forceFill(['upload_post_key' => null])->save();
        $source = $this->fakeSource();

        $this->assertSame('suspended', app(SyncOrganizationEngagementAction::class)($suspended)['skipped']);
        $this->assertSame('no_vendor_key', app(SyncOrganizationEngagementAction::class)($keyless)['skipped']);
        $this->assertSame([], $source->postCalls);
    }

    public function test_the_command_queues_one_job_per_active_org_with_a_key(): void
    {
        Queue::fake();
        $active = $this->engagementOrg();
        $this->engagementOrg()->forceFill(['suspended_at' => now()])->save();
        $keyless = $this->engagementOrg();
        $keyless->forceFill(['upload_post_key' => null])->save();

        $this->artisan('analytics:sync-engagement')->assertSuccessful();

        Queue::assertPushed(SyncOrganizationEngagementJob::class, 1);
        Queue::assertPushed(SyncOrganizationEngagementJob::class, fn ($job) => $job->organizationId === $active->id && $job->queue === 'reports');
    }

    public function test_the_webhook_records_the_live_post_and_when_it_went_out(): void
    {
        $this->travelTo('2026-10-20 10:00:00');
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org));
        $post = Post::factory()->for($account->client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $target = $post->targets()->create(['social_account_id' => $account->id, 'status' => PostTargetStatus::Pending, 'external_post_id' => 'job-77']);

        $this->postJson(route('webhooks.upload-post'), [
            'event' => 'upload_completed',
            'job_id' => 'job-77',
            'request_id' => 'req-77',
            'platform' => 'instagram',
            'result' => ['success' => true, 'url' => 'https://www.instagram.com/p/xyz/', 'publish_id' => '1799'],
        ])->assertOk();

        /** @var PostTarget $target */
        $target = $target->fresh();
        $this->assertSame(PostTargetStatus::Published, $target->status);
        $this->assertSame('req-77', $target->vendor_request_id);
        $this->assertSame('1799', $target->platform_post_id);
        $this->assertSame('https://www.instagram.com/p/xyz/', $target->post_url);
        $this->assertSame('2026-10-20 10:00:00', $target->published_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_non_http_post_url_from_the_vendor_is_dropped(): void
    {
        $org = $this->engagementOrg();
        $account = $this->engagementAccount($this->engagementClient($org));
        $post = Post::factory()->for($account->client)->create(['org_id' => $org->id, 'status' => PostStatus::Publishing]);
        $target = $post->targets()->create(['social_account_id' => $account->id, 'status' => PostTargetStatus::Pending, 'external_post_id' => 'job-78']);

        $this->postJson(route('webhooks.upload-post'), [
            'event' => 'upload_completed',
            'job_id' => 'job-78',
            'platform' => 'instagram',
            'result' => ['success' => true, 'url' => 'javascript:alert(1)'],
        ])->assertOk();

        $this->assertNull($target->fresh()?->post_url);
    }

    /**
     * @param  list<PostMetricsReading|null>  $posts
     * @param  list<AccountMetricsReading|null>  $accounts
     */
    private function fakeSource(array $posts = [], array $accounts = [], ?string $error = null): FakeEngagementSource
    {
        $source = new FakeEngagementSource($posts, $accounts, $error);
        $this->app->instance(EngagementSource::class, $source);

        return $source;
    }
}
