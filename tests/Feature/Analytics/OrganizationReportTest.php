<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Actions\Analytics\RunOrganizationReportAction;
use App\Enums\ReportSchedule;
use App\Models\Client;
use App\Models\OrganizationReport;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Database\Seeders\PreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsAnalyticsFixtures;
use Tests\TestCase;

/**
 * Step 17.6 — saved organization reports: CRUD, run-time permissions, storage shape, tenant
 * isolation and graceful handling of clients deleted after a report was saved.
 */
class OrganizationReportTest extends TestCase
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

    private function report(array $filters = [], array $attributes = []): OrganizationReport
    {
        return OrganizationReport::factory()->create([
            'org_id' => $this->org->id,
            'created_by' => $this->owner->id,
            'filters' => OrganizationAnalyticsFilters::fromArray($filters, $this->org)->toStoredArray(),
            ...$attributes,
        ]);
    }

    public function test_an_owner_creates_a_relative_report_and_lands_on_its_run_view(): void
    {
        $response = $this->actingAs($this->owner)->post(route('analytics.reports.store'), [
            'name' => '  Monday check-in ',
            'description' => 'For the stand-up.',
            'filters' => ['range' => '30d', 'client_ids' => [$this->acme->id], 'metrics' => ['posts', 'approvals']],
        ]);

        $report = OrganizationReport::query()->sole();
        $response->assertRedirect(route('analytics.reports.show', $report));

        $this->assertSame($this->org->id, $report->org_id);
        $this->assertSame('Monday check-in', $report->name);
        $this->assertSame($this->owner->id, $report->created_by);
        // Presets stay relative: no dates stored.
        $this->assertSame([
            'range' => '30d', 'from' => null, 'to' => null,
            'client_ids' => [$this->acme->id], 'campaign_ids' => [], 'platforms' => [], 'metrics' => ['posts', 'approvals'],
        ], $report->filters);
        $this->assertNull($report->schedule);
    }

    public function test_custom_ranges_stay_pinned_in_storage(): void
    {
        $this->actingAs($this->strategist)->post(route('analytics.reports.store'), [
            'name' => 'September',
            'filters' => ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'],
        ])->assertRedirect();

        $this->assertSame(
            ['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'],
            collect(OrganizationReport::query()->sole()->filters)->only(['range', 'from', 'to'])->all(),
        );
    }

    public function test_validation_and_tenant_safe_filters(): void
    {
        $this->actingAs($this->owner)->post(route('analytics.reports.store'), [
            'name' => '',
            'filters' => ['range' => 'custom', 'client_ids' => [$this->otherClient->id]],
        ])->assertSessionHasErrors(['name', 'filters.from', 'filters.to', 'filters.client_ids.0']);

        $this->assertDatabaseCount('organization_reports', 0);
    }

    public function test_running_a_report_equals_calling_the_action_directly(): void
    {
        $report = $this->report(['range' => '90d', 'platforms' => ['instagram']]);

        $direct = app(GetOrganizationAnalyticsSummaryAction::class)(
            $this->org,
            OrganizationAnalyticsFilters::fromArray(['range' => '90d', 'platforms' => ['instagram']], $this->org),
            $this->strategist,
        );

        $this->assertSame($direct, app(RunOrganizationReportAction::class)($report, $this->strategist));
        $this->assertNotNull($report->refresh()->last_run_at);

        $this->actingAs($this->strategist)
            ->get(route('analytics.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/reports/show')
                ->where('report.name', $report->name)
                ->where('report.can.update', false)
                ->where('summary.posts.published', $direct['posts']['published']));
    }

    public function test_permissions_apply_when_the_report_runs_not_when_it_was_saved(): void
    {
        $report = $this->report(['metrics' => ['revenue', 'posts']]);

        $this->actingAs($this->viewer)
            ->get(route('analytics.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.revenue', null)
                ->where('summary.metrics', ['posts'])
                ->has('summary.posts'));

        $this->actingAs($this->owner)
            ->get(route('analytics.reports.show', $report))
            ->assertInertia(fn ($page) => $page->where('summary.revenue.invoices_issued', 3));
    }

    public function test_a_designer_can_view_but_not_create_or_edit(): void
    {
        $report = $this->report();

        $this->actingAs($this->designer)->get(route('analytics.reports.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/reports/index')
                ->has('reports', 1)
                ->where('can.create', false));
        $this->actingAs($this->designer)->get(route('analytics.reports.show', $report))->assertOk();
        $this->actingAs($this->designer)->get(route('analytics.reports.create'))->assertForbidden();
        $this->actingAs($this->designer)->post(route('analytics.reports.store'), ['name' => 'x', 'filters' => ['range' => '7d']])->assertForbidden();
        $this->actingAs($this->designer)->get(route('analytics.reports.edit', $report))->assertForbidden();
        $this->actingAs($this->designer)->put(route('analytics.reports.update', $report), ['name' => 'x', 'filters' => ['range' => '7d']])->assertForbidden();
        $this->actingAs($this->designer)->delete(route('analytics.reports.destroy', $report))->assertForbidden();
    }

    public function test_only_the_creator_or_an_owner_can_change_a_report(): void
    {
        $strategistReport = $this->report(attributes: ['created_by' => $this->strategist->id]);
        $ownerReport = $this->report();
        $payload = ['name' => 'Renamed', 'filters' => ['range' => '7d']];

        $this->actingAs($this->strategist)->put(route('analytics.reports.update', $ownerReport), $payload)->assertForbidden();
        $this->actingAs($this->strategist)->put(route('analytics.reports.update', $strategistReport), $payload)
            ->assertRedirect(route('analytics.reports.show', $strategistReport));
        $this->assertSame('Renamed', $strategistReport->refresh()->name);
        $this->assertSame('7d', $strategistReport->filters['range']);

        $this->actingAs($this->owner)->delete(route('analytics.reports.destroy', $strategistReport))
            ->assertRedirect(route('analytics.reports.index'));
        $this->assertModelMissing($strategistReport);
    }

    public function test_a_report_from_another_organization_is_forbidden(): void
    {
        $foreign = OrganizationReport::factory()->create(['org_id' => $this->otherOrg->id]);

        foreach ([
            ['get', route('analytics.reports.show', $foreign)],
            ['get', route('analytics.reports.edit', $foreign)],
            ['get', route('analytics.reports.export', $foreign)],
            ['get', route('analytics.reports.pdf', $foreign)],
            ['put', route('analytics.reports.update', $foreign)],
            ['delete', route('analytics.reports.destroy', $foreign)],
        ] as [$method, $url]) {
            $this->actingAs($this->owner)->{$method}($url, ['name' => 'x', 'filters' => ['range' => '7d']])->assertForbidden();
        }

        $this->actingAs($this->owner)->get(route('analytics.reports.index'))
            ->assertInertia(fn ($page) => $page->has('reports', 0));
    }

    public function test_a_client_deleted_after_saving_is_dropped_quietly(): void
    {
        $doomed = Client::factory()->for($this->org, 'organization')->create(['name' => 'Gone Ltd']);
        $report = $this->report(['client_ids' => [$doomed->id, $this->acme->id]]);
        $doomed->delete();

        $this->actingAs($this->owner)
            ->get(route('analytics.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.filters.client_ids', [$this->acme->id])
                ->where('summary.scope', 'client'));

        $this->actingAs($this->owner)
            ->get(route('analytics.reports.index'))
            ->assertInertia(fn ($page) => $page->where('reports.0.client_names', ['Acme']));
    }

    public function test_the_builder_prefills_from_the_analytics_query_string(): void
    {
        $this->actingAs($this->owner)
            ->get(route('analytics.reports.create', ['range' => 'ytd', 'client_ids' => [$this->bolt->id], 'metrics' => ['approvals']]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('analytics/reports/edit')
                ->where('report', null)
                ->where('defaults.filters.range', 'ytd')
                ->where('defaults.filters.client_ids', [$this->bolt->id])
                ->where('defaults.filters.metrics', ['approvals'])
                ->where('recipientOptions', fn ($people) => collect($people)->pluck('label')->all() === ['Dee Designer', 'Olive Owner', 'Sam Strategist', 'Vic Viewer'])
                ->has('scheduleOptions', 2));
    }

    public function test_schedules_keep_only_same_org_staff_recipients(): void
    {
        $this->actingAs($this->owner)->post(route('analytics.reports.store'), [
            'name' => 'Weekly',
            'filters' => ['range' => '7d'],
            'schedule' => 'weekly',
            'recipient_ids' => [$this->reviewer->id],
        ])->assertSessionHasErrors('recipient_ids.0');

        $this->actingAs($this->owner)->post(route('analytics.reports.store'), [
            'name' => 'Weekly',
            'filters' => ['range' => '7d'],
            'schedule' => 'weekly',
        ])->assertSessionHasErrors('recipient_ids');

        $this->actingAs($this->owner)->post(route('analytics.reports.store'), [
            'name' => 'Weekly',
            'filters' => ['range' => '7d'],
            'schedule' => 'weekly',
            'recipient_ids' => [$this->designer->id, $this->owner->id],
        ])->assertRedirect();

        $report = OrganizationReport::query()->sole();
        $this->assertSame(ReportSchedule::Weekly, $report->schedule);
        $this->assertSame([$this->owner->id, $this->designer->id], $report->recipientIds());
    }

    public function test_changing_the_schedule_resets_the_send_marker(): void
    {
        $report = $this->report(attributes: [
            'schedule' => ReportSchedule::Weekly,
            'recipient_ids' => [$this->owner->id],
            'last_sent_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)->put(route('analytics.reports.update', $report), [
            'name' => $report->name,
            'filters' => ['range' => '30d'],
            'schedule' => 'monthly',
            'recipient_ids' => [$this->owner->id],
        ])->assertRedirect();

        $report->refresh();
        $this->assertSame(ReportSchedule::Monthly, $report->schedule);
        $this->assertNull($report->last_sent_at);

        // Turning the schedule off clears recipients too.
        $this->actingAs($this->owner)->put(route('analytics.reports.update', $report), [
            'name' => $report->name,
            'filters' => ['range' => '30d'],
        ])->assertRedirect();
        $this->assertNull($report->refresh()->schedule);
        $this->assertNull($report->recipient_ids);
    }

    public function test_the_preview_mirror_is_read_only(): void
    {
        $this->seed(PreviewSeeder::class);

        $this->get(route('preview.analytics.reports.index'))->assertOk();
    }
}
