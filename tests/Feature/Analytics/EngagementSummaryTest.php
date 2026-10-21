<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Actions\Campaigns\GetCampaignResultsAction;
use App\Enums\CampaignStatus;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Services\CampaignKpiService;
use App\Support\Analytics\OrganizationAnalyticsCsv;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Analytics\Concerns\BuildsEngagementFixtures;
use Tests\TestCase;

/**
 * Step M-3.1 / I-10.1 (Phase 12 Step 12.2) — the engagement section: exact totals and rate from
 * the latest reading per channel, nulls (never zeros) for unreported metrics, "not available" for
 * platforms with no source, follower growth, top posts, tenant isolation, the client drill-down,
 * campaign KPIs (C-7.1), top posts by engagement (C-7.2) and the frozen wrap snapshot (C-7.3).
 */
class EngagementSummaryTest extends TestCase
{
    use BuildsEngagementFixtures, RefreshDatabase;

    private Organization $org;

    private Client $acme;

    private Client $bolt;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');

        $this->org = $this->engagementOrg();
        $this->acme = $this->engagementClient($this->org, 'Acme');
        $this->bolt = $this->engagementClient($this->org, 'Bolt');
        $this->owner = User::factory()->for($this->org, 'organization')->role(Role::Owner)->create();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function engagement(array $input = [], ?User $viewer = null, ?Organization $org = null): array
    {
        $org ??= $this->org;

        return app(GetOrganizationAnalyticsSummaryAction::class)(
            $org,
            OrganizationAnalyticsFilters::fromArray($input, $org),
            $viewer ?? $this->owner,
        )['engagement'];
    }

    public function test_totals_and_rate_use_the_latest_reading_per_channel(): void
    {
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $fb = $this->engagementAccount($this->acme, Platform::Facebook);
        $post = $this->publishedPost($this->acme, [$ig, $fb], now()->subDays(3), ['master_caption' => 'Autumn menu launch']);

        $this->reading($post, $ig, ['likes' => 10, 'reach' => 100]); // superseded
        $this->reading($post, $ig, ['likes' => 40, 'comments' => 5, 'shares' => 3, 'saves' => 2, 'reach' => 1000]);
        $this->reading($post, $fb, ['likes' => 20, 'impressions' => 500]);

        $engagement = $this->engagement();

        $this->assertSame(2, $engagement['published_channels']);
        $this->assertSame(2, $engagement['measured_channels']);
        $this->assertSame(70, $engagement['totals']['engagement']);
        $this->assertSame(1000, $engagement['totals']['reach']);
        $this->assertSame(500, $engagement['totals']['impressions']);
        // Unreported metrics are null, never zero.
        $this->assertNull($engagement['totals']['views']);
        $this->assertNull($engagement['totals']['clicks']);
        // (50 + 20) ÷ (1000 reach + 500 impressions as Facebook's audience) = 4.67%.
        $this->assertSame(4.67, $engagement['engagement_rate']);

        $this->assertSame('Autumn menu launch', $engagement['top_posts'][0]['caption']);
        $this->assertSame(['instagram', 'facebook'], $engagement['top_posts'][0]['platforms']);
        $this->assertSame('Acme', $engagement['top_posts'][0]['client_name']);
    }

    public function test_a_platform_without_post_metrics_says_so_instead_of_showing_zeros(): void
    {
        $gbp = $this->engagementAccount($this->acme, Platform::GoogleBusiness);
        $this->publishedPost($this->acme, [$gbp], now()->subDay());

        $row = collect($this->engagement()['by_platform'])->firstWhere('platform', 'google_business');

        $this->assertFalse($row['available']);
        $this->assertSame("Post engagement isn't available for Google Business.", $row['note']);
        $this->assertSame(1, $row['published']);
        $this->assertNull($row['engagement']);
    }

    public function test_follower_growth_compares_the_start_and_end_of_the_range(): void
    {
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $tt = $this->engagementAccount($this->bolt, Platform::TikTok);
        $this->followersOn($ig, '2026-09-10', 800); // before the 30-day range: the starting point
        $this->followersOn($ig, '2026-10-01', 900);
        $this->followersOn($ig, '2026-10-19', 1000);
        $this->followersOn($tt, '2026-10-05', 300);
        $this->followersOn($tt, '2026-10-18', 290);

        $followers = $this->engagement()['followers'];

        $this->assertSame(1290, $followers['current']);
        $this->assertSame(200 + (-10), $followers['growth']);
        $this->assertSame([1000, 290], array_column($followers['accounts'], 'followers'));
        $this->assertArrayNotHasKey('by_day', $followers);

        $series = collect($this->engagement()['series'])->keyBy('key');
        $this->assertSame(800, $series['2026-09-21']['followers']);
        $this->assertSame(900 + 300, $series['2026-10-05']['followers']);
        $this->assertSame(1000 + 290, $series['2026-10-19']['followers']);
    }

    public function test_top_posts_rank_by_interactions_then_audience(): void
    {
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $low = $this->publishedPost($this->acme, [$ig], now()->subDays(5), ['master_caption' => 'Low']);
        $high = $this->publishedPost($this->acme, [$ig], now()->subDays(4), ['master_caption' => 'High']);
        $tieA = $this->publishedPost($this->acme, [$ig], now()->subDays(3), ['master_caption' => 'Tie wide']);
        $unmeasured = $this->publishedPost($this->acme, [$ig], now()->subDays(2), ['master_caption' => 'No reading']);
        $this->reading($low, $ig, ['likes' => 5]);
        $this->reading($high, $ig, ['likes' => 50]);
        $this->reading($tieA, $ig, ['likes' => 5, 'reach' => 999]);

        $captions = array_column($this->engagement()['top_posts'], 'caption');

        $this->assertSame(['High', 'Tie wide', 'Low'], $captions);
        $this->assertNotContains($unmeasured->master_caption, $captions);
    }

    public function test_engagement_is_tenant_isolated_and_follows_the_client_filter(): void
    {
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $tt = $this->engagementAccount($this->bolt, Platform::TikTok);
        $acmePost = $this->publishedPost($this->acme, [$ig], now()->subDay());
        $boltPost = $this->publishedPost($this->bolt, [$tt], now()->subDay());
        $this->reading($acmePost, $ig, ['likes' => 10]);
        $this->reading($boltPost, $tt, ['likes' => 7]);

        $other = $this->engagementOrg();
        $foreign = $this->engagementAccount($this->engagementClient($other), Platform::Instagram);
        $foreignPost = $this->publishedPost($foreign->client, [$foreign], now()->subDay());
        $this->reading($foreignPost, $foreign, ['likes' => 1000]);

        $this->assertSame(17, $this->engagement()['totals']['engagement']);
        $this->assertSame(10, $this->engagement(['client_ids' => [$this->acme->id]])['totals']['engagement']);
        $this->assertSame(7, $this->engagement(['platforms' => ['tiktok']])['totals']['engagement']);

        $otherOwner = User::factory()->for($other, 'organization')->role(Role::Owner)->create();
        $this->assertSame(1000, $this->engagement([], $otherOwner, $other)['totals']['engagement']);
    }

    public function test_every_analytics_role_sees_engagement_and_the_page_carries_it(): void
    {
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $post = $this->publishedPost($this->acme, [$ig], now()->subDay());
        $this->reading($post, $ig, ['likes' => 12]);

        foreach ([Role::Strategist, Role::Designer, Role::Viewer] as $role) {
            $viewer = User::factory()->for($this->org, 'organization')->role($role)->create();
            $this->assertSame(12, $this->engagement([], $viewer)['totals']['engagement'], $role->value);
        }

        $this->actingAs($this->owner)
            ->get(route('clients.analytics', $this->acme))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.engagement.totals.engagement', 12));
    }

    public function test_the_csv_export_carries_the_engagement_tables(): void
    {
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $post = $this->publishedPost($this->acme, [$ig], now()->subDay(), ['master_caption' => 'CSV post']);
        $this->reading($post, $ig, ['likes' => 3, 'reach' => 30]);

        $summary = app(GetOrganizationAnalyticsSummaryAction::class)($this->org, OrganizationAnalyticsFilters::fromArray([], $this->org), $this->owner);
        $rows = OrganizationAnalyticsCsv::rows($summary);

        $this->assertContains(['engagement', 'totals_engagement', 3, null], $rows);
        $this->assertContains(['engagement', 'by_platform_instagram_engagement', 3, null], $rows);
        $this->assertContains(['engagement', 'by_platform_instagram_available', 'yes', null], $rows);
        $this->assertEmpty(array_filter($rows, fn ($row) => ($row[0] ?? null) === 'engagement' && str_starts_with((string) $row[1], 'top_posts')));
        $this->assertContains('CSV post', array_merge(...array_filter($rows, fn ($row) => in_array('CSV post', $row, true))));
    }

    public function test_campaign_engagement_kpis_turn_on_once_readings_exist(): void
    {
        $campaign = Campaign::factory()->for($this->acme)->create(['org_id' => $this->org->id, 'status' => CampaignStatus::Active, 'start_date' => '2026-10-01']);
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $kpis = app(CampaignKpiService::class);

        $before = $kpis->actuals($campaign);
        $this->assertFalse($before['reach']['available']);
        $this->assertNull($before['reach']['actual']);
        $this->assertSame(CampaignKpiService::UNAVAILABLE_NOTE, $before['engagement_rate']['note']);

        $post = $this->publishedPost($this->acme, [$ig], now()->subDays(2), ['campaign_id' => $campaign->id]);
        $outside = $this->publishedPost($this->acme, [$ig], now()->subDays(2));
        $this->reading($post, $ig, ['likes' => 30, 'comments' => 10, 'reach' => 800, 'impressions' => 1000]);
        $this->reading($outside, $ig, ['likes' => 999, 'reach' => 1]);
        $this->followersOn($ig, '2026-10-02', 500);
        $this->followersOn($ig, '2026-10-19', 560);

        $after = $kpis->actuals($campaign->fresh());

        $this->assertSame(800.0, $after['reach']['actual']);
        $this->assertSame(1000.0, $after['impressions']['actual']);
        $this->assertSame(5.0, $after['engagement_rate']['actual']);
        $this->assertSame(60.0, $after['follower_growth']['actual']);
        $this->assertTrue($after['follower_growth']['available']);
        // Nobody reported link clicks: honest, not zero.
        $this->assertFalse($after['link_clicks']['available']);
        $this->assertSame(CampaignKpiService::NO_CLICKS_NOTE, $after['link_clicks']['note']);

        $results = app(GetCampaignResultsAction::class)($campaign->fresh(), $this->owner);
        $this->assertSame('engagement', $results['topPostsRankedBy']);
        $this->assertSame([$post->id], array_column($results['topPosts'], 'id'));
        $this->assertSame(40, $results['topPosts'][0]['engagement']);
        $this->assertTrue(collect($results['metrics'])->firstWhere('value', 'reach')['available']);
    }

    public function test_campaign_top_posts_fall_back_to_approval_speed_without_readings(): void
    {
        $campaign = Campaign::factory()->for($this->acme)->create(['org_id' => $this->org->id]);

        $results = app(GetCampaignResultsAction::class)($campaign, $this->owner);

        $this->assertSame('engagement', $results['topPostsRankedBy']); // nothing to rank: the default label
        $this->assertSame([], $results['topPosts']);
    }

    public function test_a_shared_wrap_report_freezes_engagement_and_earlier_snapshots_never_change(): void
    {
        Mail::fake();
        $campaign = Campaign::factory()->for($this->acme)->create(['org_id' => $this->org->id, 'status' => CampaignStatus::Active, 'start_date' => '2026-10-01']);
        $ig = $this->engagementAccount($this->acme, Platform::Instagram);
        $post = $this->publishedPost($this->acme, [$ig], now()->subDays(2), ['campaign_id' => $campaign->id, 'master_caption' => 'Launch post']);
        $this->reading($post, $ig, ['likes' => 30, 'reach' => 600]);

        // A report shared before engagement existed stays without an engagement section.
        $legacy = $campaign->report()->create(['summary' => 'Old', 'status' => 'shared', 'version' => 1, 'kpi_snapshot' => []]);
        $this->assertNull($legacy->engagement_snapshot);

        $legacy->update(['status' => 'draft']);
        $this->actingAs($this->owner)->put(route('campaigns.report.update', $campaign), ['summary' => 'Strong launch.'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('campaigns.report.share', $campaign))->assertSessionHasNoErrors();

        $snapshot = $campaign->report()->firstOrFail()->engagement_snapshot;
        $this->assertSame(30, $snapshot['totals']['engagement']);
        $this->assertSame(600, $snapshot['totals']['reach']);
        $this->assertSame('Launch post', $snapshot['top_posts'][0]['caption']);
        $this->assertArrayNotHasKey('thumbnail_url', $snapshot['top_posts'][0]);

        // New readings arrive after sharing: the shared numbers stay frozen.
        $this->reading($post, $ig, ['likes' => 300, 'reach' => 6000]);
        $this->assertSame(30, $campaign->report()->firstOrFail()->engagement_snapshot['totals']['engagement']);

        $this->actingAs(User::factory()->for($this->org, 'organization')->role(Role::ClientReviewer)->create(['client_id' => $this->acme->id]))
            ->get(route('portal.campaigns.show', $campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('report.engagement_snapshot.totals.engagement', 30));
    }
}
