<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Enums\Platform;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsAnalyticsFixtures;
use Tests\TestCase;

/**
 * Step 17.3 — exact per-section figures for one organization against hand-built fixtures, tenant
 * isolation, role gating, filters, timezone bucketing and the empty state.
 */
class OrganizationAnalyticsSummaryTest extends TestCase
{
    use BuildsAnalyticsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAnalyticsFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownAnalyticsFixtures();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function summary(array $input = [], ?User $viewer = null, ?Organization $organization = null): array
    {
        $organization ??= $this->org;

        return app(GetOrganizationAnalyticsSummaryAction::class)(
            $organization,
            OrganizationAnalyticsFilters::fromArray($input, $organization),
            $viewer ?? $this->owner,
        );
    }

    public function test_the_range_follows_the_organizations_timezone(): void
    {
        $summary = $this->summary();

        $this->assertSame('organization', $summary['scope']);
        $this->assertSame([
            'range' => '30d',
            'from' => '2026-09-18',
            'to' => '2026-10-17',
            'granularity' => 'day',
            'timezone' => 'Europe/London',
            'client_ids' => [],
            'campaign_ids' => [],
            'platforms' => [],
            'metrics' => [],
        ], $summary['filters']);
        $this->assertSame(['clients', 'posts', 'approvals', 'team', 'revenue', 'ai'], $summary['metrics']);
        $this->assertNull($summary['engagement']);
        $this->assertSame('GBP', $summary['primary_currency']);
    }

    public function test_clients_section(): void
    {
        $clients = $this->summary()['clients'];

        $this->assertSame(3, $clients['total']);
        $this->assertSame(2, $clients['active']);
        $this->assertSame(1, $clients['paused']);
        $this->assertSame(0, $clients['offboarding']);
        $this->assertSame(1, $clients['new']);
        $this->assertSame(['green' => 1, 'amber' => 1, 'red' => 1], $clients['health']);
        $this->assertSame(['Crest', 'Bolt'], array_column($clients['attention'], 'name'));
        $this->assertSame(['red', 'amber'], array_column($clients['attention'], 'health'));
        $this->assertSame('Active client has no assigned owner.', $clients['attention'][0]['reason']);
    }

    public function test_posts_section(): void
    {
        $posts = $this->summary()['posts'];

        $this->assertSame(5, $posts['created']);
        $this->assertSame(2, $posts['published']);
        $this->assertSame(1, $posts['failed']);
        $this->assertSame(66.7, $posts['success_rate']);
        $this->assertSame(1, $posts['scheduled']);

        $pipeline = array_column($posts['pipeline'], 'count', 'status');
        $this->assertSame(2, $pipeline['published']);
        $this->assertSame(1, $pipeline['failed']);
        $this->assertSame(2, $pipeline['waiting_client']);
        $this->assertSame(1, $pipeline['scheduled']);
        $this->assertSame(0, $pipeline['draft']);

        $byPlatform = array_column($posts['by_platform'], 'published', 'platform');
        // Step M-2.6: every registry platform is listed, zeros included.
        $this->assertSame(2, $byPlatform['instagram']);
        $this->assertSame(0, array_sum($byPlatform) - 2);
        $this->assertSame(array_map(fn (Platform $platform) => $platform->value, Platform::cases()), array_keys($byPlatform));
        $this->assertSame([[
            'id' => $this->launch->id,
            'name' => 'Autumn launch',
            'client_id' => $this->acme->id,
            'client_name' => 'Acme',
            'status' => $this->launch->status->value,
            'published' => 1,
            'scheduled' => 0,
            'waiting_on_client' => 0,
        ]], $posts['top_campaigns']);
    }

    public function test_approvals_section_turnaround_on_a_known_set_of_transitions(): void
    {
        $approvals = $this->summary()['approvals'];

        // The AI-image note row on p4 (waiting → waiting) is not a send.
        $this->assertSame(5, $approvals['sent_to_client']);
        $this->assertSame(2, $approvals['approved']);
        $this->assertSame(1, $approvals['changes_requested']);
        $this->assertSame(50.0, $approvals['first_time_approval_rate']);
        // Client decisions after 6h, 24h and 30h.
        $this->assertSame(['decisions' => 3, 'median_hours' => 24.0, 'p90_hours' => 28.8], $approvals['client_turnaround']);
        $this->assertSame(['reviews' => 1, 'median_hours' => 4.0, 'p90_hours' => 4.0], $approvals['internal_turnaround']);
        $this->assertSame(2, $approvals['waiting_now']);
        $this->assertSame($this->posts['p3']->id, $approvals['oldest_waiting']['post_id']);
        $this->assertSame('Bolt', $approvals['oldest_waiting']['client_name']);
        $this->assertSame(52.0, $approvals['oldest_waiting']['hours_waiting']);
    }

    public function test_team_section_is_workload_sorted_by_name_with_client_decisions_apart(): void
    {
        $team = $this->summary()['team'];

        $this->assertSame(['Dee Designer', 'Olive Owner', 'Sam Strategist', 'Vic Viewer'], array_column($team['members'], 'name'));
        $this->assertNotContains('Rae Reviewer', array_column($team['members'], 'name'));
        $this->assertNotContains('Aaron Elsewhere', array_column($team['members'], 'name'));

        $byName = collect($team['members'])->keyBy('name');
        $this->assertSame(
            ['posts_created' => 3, 'transitions' => 5, 'sent_to_client' => 3, 'scheduled' => 1],
            collect($byName['Sam Strategist'])->only(['posts_created', 'transitions', 'sent_to_client', 'scheduled'])->all(),
        );
        $this->assertSame(1, $byName['Dee Designer']['transitions']);
        $this->assertSame(1, $byName['Olive Owner']['transitions']);
        $this->assertSame(0, $byName['Vic Viewer']['transitions']);
        $this->assertSame(3, $team['client_decisions']);
        $this->assertSame(['posts_created' => 5, 'transitions' => 7, 'sent_to_client' => 5, 'scheduled' => 1], $team['totals']);
    }

    public function test_revenue_section_never_sums_across_currencies(): void
    {
        $revenue = $this->summary()['revenue'];

        $this->assertSame([['currency' => 'AED', 'total' => 2000.0], ['currency' => 'GBP', 'total' => 1500.0]], $revenue['invoiced']);
        $this->assertSame([['currency' => 'GBP', 'total' => 500.0]], $revenue['collected']);
        $this->assertSame([['currency' => 'AED', 'total' => 2000.0], ['currency' => 'GBP', 'total' => 1300.0]], $revenue['outstanding']);
        $this->assertSame([['currency' => 'AED', 'total' => 2000.0]], $revenue['overdue']);
        $this->assertSame(1, $revenue['overdue_count']);
        $this->assertSame($revenue['invoiced'], $revenue['monthly_run_rate']);
        $this->assertSame(3, $revenue['invoices_issued']);
        $this->assertSame(1, $revenue['invoices_paid']);
        $this->assertSame(15, $revenue['average_days_to_pay']);
        // Acme 1,200/month + Crest 2,400/quarter; Bolt is paused.
        $this->assertSame(['monthly' => [['currency' => 'GBP', 'total' => 2000.0]], 'clients' => 2], $revenue['retainers']);
    }

    public function test_ai_section(): void
    {
        $ai = $this->summary()['ai'];

        $this->assertSame([
            'captions' => [0, 0],
            'trends' => [2, 0],
            'ideas' => [1, 0],
            'daily_brief' => [1, 0],
            'images' => [4, 1],
        ], collect($ai['by_method'])->mapWithKeys(fn ($row) => [$row['method'] => [$row['succeeded'], $row['failed']]])->all());
        $this->assertSame(8, $ai['generated']);
        $this->assertSame(1, $ai['failed']);
        $this->assertSame(['used' => 0, 'quota' => 200, 'remaining' => 200], $ai['images_this_month']);
        $this->assertSame(0.14, $ai['cost_estimate']);
    }

    public function test_series_and_top_clients(): void
    {
        $summary = $this->summary();
        $series = collect($summary['series'])->keyBy('key');

        $this->assertCount(30, $summary['series']);
        $this->assertSame(5, $series->sum('created'));
        $this->assertSame(2, $series->sum('published'));
        $this->assertSame(1, $series->sum('failed'));
        $this->assertSame(5, $series->sum('sent_for_approval'));
        $this->assertSame(2, $series->sum('approved'));
        $this->assertEqualsWithDelta(1500.0, $series->sum('invoiced'), 0.001);

        $this->assertSame(['Acme', 'Crest', 'Bolt'], array_column($summary['top_clients'], 'name'));
        $this->assertEquals(['published' => 1, 'scheduled' => 1, 'waiting_on_client' => 1, 'invoiced' => 1500.0, 'health' => 'green'],
            collect($summary['top_clients'][0])->only(['published', 'scheduled', 'waiting_on_client', 'invoiced', 'health'])->all());
        $this->assertNull($summary['top_campaigns']);
    }

    public function test_daily_buckets_use_the_organizations_timezone(): void
    {
        // p6 went out at 23:30 UTC on 16 Oct — 00:30 on 17 Oct in London (BST).
        $london = collect($this->summary()['series'])->keyBy('key');
        $this->assertSame(1, $london['2026-10-17']['published']);
        $this->assertSame(0, $london['2026-10-16']['published']);

        $this->org->update(['default_timezone' => 'UTC']);
        $utc = collect($this->summary(organization: $this->org->refresh())['series'])->keyBy('key');
        $this->assertSame(0, $utc['2026-10-17']['published']);
        $this->assertSame(1, $utc['2026-10-16']['published']);
    }

    public function test_another_organizations_data_never_appears_in_any_section(): void
    {
        $summary = $this->summary();
        $json = json_encode($summary, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Foreign Client', $json);
        $this->assertStringNotContainsString('Aaron Elsewhere', $json);
        $this->assertStringNotContainsString('Foreign trend', $json);
        $this->assertStringNotContainsString('USD', json_encode($summary['revenue'], JSON_THROW_ON_ERROR));
        $this->assertSame([['currency' => 'AED', 'total' => 2000.0], ['currency' => 'GBP', 'total' => 1300.0]], $summary['revenue']['outstanding']);
        $this->assertSame(0, collect($summary['posts']['by_platform'])->firstWhere('platform', 'facebook')['published']);

        // And the other org sees only its own.
        $other = $this->summary(viewer: User::query()->where('org_id', $this->otherOrg->id)->firstOrFail(), organization: $this->otherOrg);
        $this->assertSame(4, $other['posts']['published']);
        $this->assertSame(1, $other['clients']['total']);
        $this->assertSame([['currency' => 'USD', 'total' => 99000.0], ['currency' => 'GBP', 'total' => 5000.0]], $other['revenue']['invoiced']);
        $this->assertSame(['Aaron Elsewhere'], array_column($other['team']['members'], 'name'));
        $this->assertSame(9.99, $other['ai']['cost_estimate']);
    }

    public function test_sections_are_gated_per_role(): void
    {
        $strategist = $this->summary(viewer: $this->strategist);
        $this->assertIsArray($strategist['revenue']);
        $this->assertNull($strategist['ai']['cost_estimate']);
        $this->assertArrayHasKey('invoiced', $strategist['series'][0]);

        foreach ([$this->designer, $this->viewer] as $viewer) {
            $summary = $this->summary(viewer: $viewer);

            $this->assertNull($summary['revenue'], $viewer->role->value);
            $this->assertNotContains('revenue', $summary['metrics']);
            $this->assertNull($summary['ai']['cost_estimate']);
            $this->assertArrayNotHasKey('invoiced', $summary['series'][0]);
            $this->assertArrayNotHasKey('invoiced', $summary['top_clients'][0]);
            $this->assertIsArray($summary['approvals']);
            $this->assertIsArray($summary['team']);
            // The primary currency still reads from the org, but never from invoice totals.
            $this->assertSame('GBP', $summary['primary_currency']);
        }

        $this->assertSame(0.14, $this->summary()['ai']['cost_estimate']);
    }

    public function test_a_designer_asking_for_revenue_gets_it_dropped_not_refused(): void
    {
        $summary = $this->summary(['metrics' => ['revenue', 'posts']], $this->designer);

        $this->assertSame(['posts'], $summary['metrics']);
        $this->assertNull($summary['revenue']);
        $this->assertNull($summary['clients']);
        $this->assertIsArray($summary['posts']);
    }

    public function test_client_and_platform_filters_narrow_every_section(): void
    {
        $bolt = $this->summary(['client_ids' => [$this->bolt->id]]);

        $this->assertSame('client', $bolt['scope']);
        $this->assertSame(['id' => $this->bolt->id, 'name' => 'Bolt', 'status' => 'paused', 'health' => 'amber', 'reason' => 'Client is paused.'], $bolt['client']);
        $this->assertSame(1, $bolt['clients']['total']);
        $this->assertSame(0, $bolt['posts']['published']);
        $this->assertSame(1, $bolt['approvals']['sent_to_client']);
        $this->assertSame([['currency' => 'AED', 'total' => 2000.0]], $bolt['revenue']['invoiced']);
        $this->assertSame(0, $bolt['ai']['generated']);
        $this->assertNull($bolt['top_clients']);
        $this->assertSame([], $bolt['top_campaigns']);

        $linkedin = $this->summary(['platforms' => [Platform::LinkedIn->value]]);
        $this->assertSame(0, $linkedin['posts']['published']);
        $this->assertSame(1, $linkedin['posts']['failed']);
        $this->assertSame(['linkedin'], array_column($linkedin['posts']['by_platform'], 'platform'));
        $this->assertSame(2, $linkedin['approvals']['sent_to_client']);

        $campaign = $this->summary(['campaign_ids' => [$this->launch->id]]);
        $this->assertSame(2, $campaign['posts']['created']);
        $this->assertSame(1, $campaign['posts']['published']);
    }

    public function test_foreign_or_deleted_ids_are_dropped_from_the_filters(): void
    {
        $deleted = Client::factory()->for($this->org, 'organization')->create();
        $deletedId = $deleted->id;
        $deleted->delete();

        $summary = $this->summary(['client_ids' => [$this->otherClient->id, $deletedId]]);

        $this->assertSame([], $summary['filters']['client_ids']);
        $this->assertSame('organization', $summary['scope']);
        $this->assertSame(2, $summary['posts']['published']);
    }

    public function test_custom_ranges_are_pinned_in_local_dates(): void
    {
        $summary = $this->summary(['range' => 'custom', 'from' => '2026-10-17', 'to' => '2026-10-17']);

        // Only p6 (00:30 BST on the 17th).
        $this->assertSame(1, $summary['posts']['published']);
        $this->assertCount(1, $summary['series']);
    }

    public function test_a_brand_new_organization_returns_zeros_and_empty_lists(): void
    {
        $fresh = Organization::factory()->create(['default_timezone' => 'Asia/Dubai', 'default_currency' => 'AED']);
        $owner = User::factory()->for($fresh, 'organization')->role(Role::Owner)->create();

        $summary = $this->summary(viewer: $owner, organization: $fresh);

        $this->assertSame(0, $summary['clients']['total']);
        $this->assertSame([], $summary['clients']['attention']);
        $this->assertSame(0, $summary['posts']['published']);
        $this->assertNull($summary['posts']['success_rate']);
        $this->assertSame([], $summary['posts']['top_campaigns']);
        $this->assertNull($summary['approvals']['first_time_approval_rate']);
        $this->assertNull($summary['approvals']['client_turnaround']['median_hours']);
        $this->assertNull($summary['approvals']['oldest_waiting']);
        $this->assertSame([], $summary['revenue']['invoiced']);
        $this->assertNull($summary['revenue']['average_days_to_pay']);
        $this->assertSame(['monthly' => [], 'clients' => 0], $summary['revenue']['retainers']);
        $this->assertSame(0, $summary['ai']['generated']);
        $this->assertSame([], $summary['top_clients']);
        $this->assertSame('AED', $summary['primary_currency']);
        $this->assertSame('Asia/Dubai', $summary['filters']['timezone']);
        $this->assertCount(30, $summary['series']);
    }

    public function test_filters_from_another_organization_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(GetOrganizationAnalyticsSummaryAction::class)(
            $this->org,
            OrganizationAnalyticsFilters::fromArray([], $this->otherOrg),
            $this->owner,
        );
    }
}
