<?php

namespace Tests\Feature\Analytics;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Support\Analytics\AnalyticsAccess;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 17.2 — the org analytics filters (timezone from the org, fallbacks, sanitising, storage),
 * the three `OrganizationPolicy` abilities across every role, and per-section visibility.
 */
class OrganizationAnalyticsAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['default_timezone' => 'Asia/Dubai']);
    }

    private function member(Role $role): User
    {
        return User::factory()->for($this->org, 'organization')->role($role)->create();
    }

    public function test_the_range_is_resolved_in_the_organizations_timezone(): void
    {
        // 22:00 UTC on the 17th is already the 18th in Dubai.
        $filters = OrganizationAnalyticsFilters::fromArray(['range' => '7d'], $this->org, CarbonImmutable::parse('2026-10-17 22:00:00', 'UTC'));

        $this->assertSame($this->org->id, $filters->orgId);
        $this->assertSame('Asia/Dubai', $filters->range->timezone);
        $this->assertSame('2026-10-12', $filters->range->from->toDateString());
        $this->assertSame('2026-10-18', $filters->range->to->toDateString());
    }

    public function test_the_org_id_can_never_come_from_input(): void
    {
        $other = Organization::factory()->create();

        $filters = OrganizationAnalyticsFilters::fromArray(['org_id' => $other->id, 'org_ids' => [$other->id]], $this->org);

        $this->assertSame($this->org->id, $filters->orgId);
        $this->assertArrayNotHasKey('org_id', $filters->toStoredArray());
    }

    public function test_bad_input_falls_back_and_is_sanitised(): void
    {
        $client = Client::factory()->for($this->org, 'organization')->create();
        $foreign = Client::factory()->create();
        $campaign = Campaign::factory()->create(['org_id' => $this->org->id, 'client_id' => $client->id]);

        $filters = OrganizationAnalyticsFilters::fromArray([
            'range' => 'forever',
            'client_ids' => [$client->id, (string) $client->id, $foreign->id, -3, 'abc', 999999],
            'campaign_ids' => [$campaign->id, 0],
            'platforms' => ['instagram', 'myspace', 'instagram'],
            'metrics' => ['posts', 'organizations', 'revenue'],
        ], $this->org);

        $this->assertSame('30d', $filters->range->preset);
        $this->assertSame([$client->id], $filters->clientIds);
        $this->assertSame([$campaign->id], $filters->campaignIds);
        $this->assertSame(['instagram'], $filters->platforms);
        $this->assertSame(['posts', 'revenue'], $filters->metrics);
        $this->assertTrue($filters->includes('posts'));
        $this->assertFalse($filters->includes('team'));
        $this->assertSame($client->id, $filters->singleClientId());
    }

    public function test_an_empty_metric_selection_means_every_section(): void
    {
        $filters = OrganizationAnalyticsFilters::fromArray([], $this->org);

        $this->assertSame(OrganizationAnalyticsFilters::METRICS, $filters->selectedMetrics());
        $this->assertTrue($filters->includes('ai'));
    }

    public function test_stored_form_keeps_presets_relative_and_custom_ranges_pinned(): void
    {
        $preset = OrganizationAnalyticsFilters::fromArray(['range' => '90d', 'platforms' => ['tiktok']], $this->org);
        $custom = OrganizationAnalyticsFilters::fromArray(['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-31'], $this->org);

        $this->assertSame([
            'range' => '90d', 'from' => null, 'to' => null,
            'client_ids' => [], 'campaign_ids' => [], 'platforms' => ['tiktok'], 'metrics' => [],
        ], $preset->toStoredArray());
        $this->assertSame('2026-07-01', $custom->toStoredArray()['from']);
        $this->assertSame('2026-07-31', $custom->toStoredArray()['to']);
        $this->assertSame(['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-31'], $custom->toQuery());
        $this->assertSame(['range' => '90d', 'platforms' => ['tiktok']], $preset->toQuery());
    }

    public function test_for_client_drops_campaigns_of_other_clients(): void
    {
        $acme = Client::factory()->for($this->org, 'organization')->create();
        $bolt = Client::factory()->for($this->org, 'organization')->create();
        $acmeCampaign = Campaign::factory()->create(['org_id' => $this->org->id, 'client_id' => $acme->id]);
        $boltCampaign = Campaign::factory()->create(['org_id' => $this->org->id, 'client_id' => $bolt->id]);

        $filters = OrganizationAnalyticsFilters::fromArray(['campaign_ids' => [$acmeCampaign->id, $boltCampaign->id]], $this->org)
            ->forClient($acme->id);

        $this->assertSame([$acme->id], $filters->clientIds);
        $this->assertSame([$acmeCampaign->id], $filters->campaignIds);
    }

    public function test_policy_matrix_across_every_role(): void
    {
        $expected = [
            // role => [viewAnalytics, viewRevenueAnalytics, viewAiCost]
            Role::Owner->value => [true, true, true],
            Role::Strategist->value => [true, true, false],
            Role::Designer->value => [true, false, false],
            Role::Viewer->value => [true, false, false],
            Role::ClientReviewer->value => [false, false, false],
        ];

        foreach ($expected as $role => [$analytics, $revenue, $cost]) {
            $user = $this->member(Role::from($role));

            $this->assertSame($analytics, $user->can('viewAnalytics', $this->org), "{$role} viewAnalytics");
            $this->assertSame($revenue, $user->can('viewRevenueAnalytics', $this->org), "{$role} viewRevenueAnalytics");
            $this->assertSame($cost, $user->can('viewAiCost', $this->org), "{$role} viewAiCost");
        }

        // Super admins pass every ability (Gate::before) — the console drill-down relies on it.
        $superAdmin = User::factory()->superAdmin()->create();
        $this->assertTrue($superAdmin->can('viewAnalytics', $this->org));
        $this->assertTrue($superAdmin->can('viewRevenueAnalytics', $this->org));
        $this->assertTrue($superAdmin->can('viewAiCost', $this->org));

        // An Owner of another organization gets nothing here.
        $outsider = User::factory()->for(Organization::factory()->create(), 'organization')->role(Role::Owner)->create();
        $this->assertFalse($outsider->can('viewAnalytics', $this->org));
        $this->assertFalse($outsider->can('viewRevenueAnalytics', $this->org));
        $this->assertFalse($outsider->can('viewAiCost', $this->org));
    }

    public function test_visible_metrics_strip_revenue_for_designers_and_cost_for_strategists(): void
    {
        $filters = OrganizationAnalyticsFilters::fromArray([], $this->org);

        $this->assertSame(['clients', 'posts', 'approvals', 'team', 'ai'], AnalyticsAccess::visibleMetrics($this->member(Role::Designer), $filters));
        $this->assertSame(['clients', 'posts', 'approvals', 'team', 'ai'], AnalyticsAccess::visibleMetrics($this->member(Role::Viewer), $filters));

        $strategist = $this->member(Role::Strategist);
        $this->assertSame(OrganizationAnalyticsFilters::METRICS, AnalyticsAccess::visibleMetrics($strategist, $filters));
        $this->assertFalse(AnalyticsAccess::canViewAiCost($strategist, $this->org));
        $this->assertTrue(AnalyticsAccess::canViewAiCost($this->member(Role::Owner), $filters));
    }
}
