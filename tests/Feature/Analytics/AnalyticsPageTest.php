<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsAnalyticsFixtures;
use Tests\TestCase;

/**
 * Steps 17.4, 17.5 and 17.9 — the analytics page and client drill-down over HTTP: props per role,
 * client reviewers locked out of every Phase 17 route, tenant-safe validation, query-string
 * round-trips, the preview mirror, and the super admin drill-down matching the Owner's view.
 */
class AnalyticsPageTest extends TestCase
{
    use BuildsAnalyticsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exports / org settings sit behind `password.confirm` (Step M-1.2); not under test here.
        $this->confirmPassword();

        $this->buildAnalyticsFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownAnalyticsFixtures();

        parent::tearDown();
    }

    /**
     * Props reach the assertion through JSON, so `2000.0` arrives as `2000` — compare like with like.
     */
    private function viaJson(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_owner_sees_every_section_and_every_ability(): void
    {
        $this->actingAs($this->owner)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/index')
                ->where('summary.metrics', ['clients', 'posts', 'approvals', 'team', 'revenue', 'ai'])
                ->where('summary.posts.published', 2)
                ->where('summary.revenue.invoiced.0.currency', 'AED')
                ->where('summary.ai.cost_estimate', 0.14)
                ->where('filters.timezone', 'Europe/London')
                ->where('filterOptions.clients', fn ($clients) => collect($clients)->pluck('label')->all() === ['Acme', 'Archived Co', 'Bolt', 'Crest'])
                ->where('filterOptions.platforms', fn ($platforms) => collect($platforms)->pluck('value')->all() === ['instagram', 'tiktok', 'linkedin'])
                ->has('filterOptions.campaigns', 1)
                ->where('can', ['viewRevenue' => true, 'viewAiCost' => true, 'saveReport' => true, 'export' => true])
                ->where('auth.can.viewAnalytics', true));
    }

    public function test_strategist_sees_revenue_but_not_ai_cost(): void
    {
        $this->actingAs($this->strategist)
            ->get(route('analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.revenue.invoices_issued', 3)
                ->where('summary.ai.cost_estimate', null)
                ->where('can.viewRevenue', true)
                ->where('can.viewAiCost', false)
                ->where('can.saveReport', true));
    }

    public function test_designer_and_viewer_get_no_revenue(): void
    {
        foreach ([$this->designer, $this->viewer] as $user) {
            $this->actingAs($user)
                ->get(route('analytics.index'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('analytics/index')
                    ->where('summary.revenue', null)
                    ->where('summary.ai.cost_estimate', null)
                    ->where('summary.series.0', fn ($bucket) => ! array_key_exists('invoiced', $bucket->toArray()))
                    ->where('summary.metrics', ['clients', 'posts', 'approvals', 'team', 'ai'])
                    ->where('can.viewRevenue', false)
                    ->where('can.viewAiCost', false)
                    ->where('can.saveReport', false)
                    ->where('auth.can.viewAnalytics', true));
        }
    }

    public function test_client_reviewers_are_refused_every_phase_17_route(): void
    {
        $report = OrganizationReport::factory()->create(['org_id' => $this->org->id, 'created_by' => $this->owner->id]);

        $routes = [
            ['get', route('analytics.index')],
            ['get', route('analytics.export')],
            ['get', route('analytics.pdf')],
            ['get', route('clients.analytics', $this->acme)],
            ['get', route('analytics.reports.index')],
            ['get', route('analytics.reports.create')],
            ['get', route('analytics.reports.show', $report)],
            ['get', route('analytics.reports.edit', $report)],
            ['get', route('analytics.reports.export', $report)],
            ['get', route('analytics.reports.pdf', $report)],
            ['post', route('analytics.reports.store')],
            ['put', route('analytics.reports.update', $report)],
            ['delete', route('analytics.reports.destroy', $report)],
        ];

        foreach ($routes as [$method, $url]) {
            $this->actingAs($this->reviewer)->{$method}($url, ['name' => 'x', 'filters' => ['range' => '30d']])
                ->assertForbidden();
        }

        $this->assertModelExists($report);
    }

    public function test_a_client_id_from_another_organization_is_a_validation_error(): void
    {
        $this->actingAs($this->strategist)
            ->from(route('analytics.index'))
            ->get(route('analytics.index', ['client_ids' => [$this->otherClient->id]]))
            ->assertRedirect(route('analytics.index'))
            ->assertSessionHasErrors('client_ids.0');
    }

    public function test_query_string_filters_round_trip_into_props(): void
    {
        $this->actingAs($this->owner)
            ->get(route('analytics.index', [
                'range' => 'custom',
                'from' => '2026-10-01',
                'to' => '2026-10-17',
                'client_ids' => [$this->acme->id],
                'campaign_ids' => [$this->launch->id],
                'platforms' => ['instagram'],
                'metrics' => ['posts', 'approvals'],
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.range', 'custom')
                ->where('filters.from', '2026-10-01')
                ->where('filters.to', '2026-10-17')
                ->where('filters.client_ids', [$this->acme->id])
                ->where('filters.campaign_ids', [$this->launch->id])
                ->where('filters.platforms', ['instagram'])
                ->where('filters.metrics', ['posts', 'approvals'])
                ->where('summary.metrics', ['posts', 'approvals'])
                ->where('summary.clients', null)
                ->where('summary.scope', 'client')
                ->where('summary.posts.published', 1));
    }

    public function test_invalid_ranges_are_validation_errors(): void
    {
        $this->actingAs($this->owner)
            ->from(route('analytics.index'))
            ->get(route('analytics.index', ['range' => 'custom', 'from' => '2026-10-10', 'to' => '2026-10-01']))
            ->assertSessionHasErrors('to');

        $this->actingAs($this->owner)
            ->from(route('analytics.index'))
            ->get(route('analytics.index', ['metrics' => ['organizations'], 'platforms' => ['myspace']]))
            ->assertSessionHasErrors(['metrics.0', 'platforms.0']);
    }

    public function test_the_preview_mirror_renders(): void
    {
        $this->seed(PreviewSeeder::class);

        $this->get(route('preview.analytics.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/index')
                ->where('preview', true)
                ->has('summary.posts'));
    }

    public function test_the_client_drill_down_matches_the_org_summary_filtered_to_that_client(): void
    {
        $direct = app(GetOrganizationAnalyticsSummaryAction::class)(
            $this->org,
            OrganizationAnalyticsFilters::fromArray(['client_ids' => [$this->acme->id]], $this->org),
            $this->owner,
        );

        $this->actingAs($this->owner)
            ->get(route('clients.analytics', $this->acme))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/client')
                ->where('client.name', 'Acme')
                ->where('summary.scope', 'client')
                ->where('summary.client.health', 'green')
                ->where('summary.top_clients', null)
                ->where('summary.top_campaigns.0.name', 'Autumn launch')
                ->where('summary.posts', $this->viaJson($direct['posts']))
                ->where('summary.approvals', $this->viaJson($direct['approvals']))
                ->where('summary.revenue', $this->viaJson($direct['revenue']))
                ->where('summary.series', $this->viaJson($direct['series']))
                ->has('filterOptions.campaigns', 1));
    }

    public function test_the_client_drill_down_is_gated_per_role_and_per_org(): void
    {
        $this->actingAs($this->designer)
            ->get(route('clients.analytics', $this->acme))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('summary.revenue', null));

        $this->actingAs($this->owner)
            ->get(route('clients.analytics', $this->otherClient))
            ->assertForbidden();
    }

    public function test_super_admin_drill_down_matches_what_the_owner_sees(): void
    {
        $owner = $this->actingAs($this->owner)->get(route('analytics.index', ['range' => '90d']))->viewData('page')['props']['summary'];

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('super-admin.organizations.analytics', [$this->org, 'range' => '90d']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/organizations/analytics')
                ->where('summary.filters', $this->viaJson($owner['filters']))
                ->where('summary.metrics', $this->viaJson($owner['metrics']))
                ->where('summary.clients', $this->viaJson($owner['clients']))
                ->where('summary.posts', $this->viaJson($owner['posts']))
                ->where('summary.approvals', $this->viaJson($owner['approvals']))
                ->where('summary.team', $this->viaJson($owner['team']))
                ->where('summary.revenue', $this->viaJson($owner['revenue']))
                ->where('summary.ai', $this->viaJson($owner['ai']))
                ->where('summary.series', $this->viaJson($owner['series']))
                ->where('summary.top_clients', $this->viaJson($owner['top_clients'])));
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('analytics.index'))->assertRedirect(route('login'));
    }
}
