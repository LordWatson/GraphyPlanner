<?php

namespace Tests\Feature\SuperAdmin;

use App\Actions\SuperAdmin\GetPlatformAnalyticsSummaryAction;
use App\Actions\SuperAdmin\RunReportAction;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\SuperAdminReport;
use App\Models\User;
use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Step 15.5 — saved report CRUD, the run view, and CSV export.
 */
class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Exports / org settings sit behind `password.confirm` (Step M-1.2); not under test here.
        $this->confirmPassword();

        Carbon::setTestNow('2026-10-17 12:00:00');

        $this->superAdmin = User::factory()->superAdmin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedActivity(): Organization
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->for($organization, 'organization')->create();
        $post = Post::factory()->for($client)->create(['status' => PostStatus::Published]);
        PostActivityLog::factory()->create([
            'post_id' => $post->id,
            'user_id' => null,
            'to_status' => PostStatus::Published,
            'created_at' => now()->subDays(3),
        ]);
        Invoice::factory()->for($client)->create([
            'status' => InvoiceStatus::Sent,
            'amount' => 1200,
            'currency' => 'GBP',
            'issue_date' => now()->subDays(4)->toDateString(),
        ]);

        return $organization;
    }

    public function test_a_super_admin_can_create_a_report(): void
    {
        $organization = Organization::factory()->create();

        $response = $this->actingAs($this->superAdmin)->post(route('super-admin.reports.store'), [
            'name' => 'Monthly board update',
            'description' => 'For the first Monday of the month.',
            'filters' => [
                'range' => '90d',
                'org_ids' => [$organization->id],
                'metrics' => ['posts', 'revenue'],
            ],
        ]);

        $report = SuperAdminReport::query()->sole();
        $response->assertRedirect(route('super-admin.reports.show', $report));

        $this->assertSame('Monthly board update', $report->name);
        $this->assertSame($this->superAdmin->id, $report->created_by);
        // Relative presets are stored without dates so they re-resolve on every run.
        $this->assertSame([
            'range' => '90d',
            'from' => null,
            'to' => null,
            'org_ids' => [$organization->id],
            'metrics' => ['posts', 'revenue'],
        ], $report->filters);
    }

    public function test_a_custom_range_report_keeps_its_dates(): void
    {
        $this->actingAs($this->superAdmin)->post(route('super-admin.reports.store'), [
            'name' => 'Q3',
            'filters' => ['range' => 'custom', 'from' => '2026-07-01', 'to' => '2026-09-30'],
        ])->assertRedirect();

        $filters = SuperAdminReport::query()->sole()->filters;

        $this->assertSame('custom', $filters['range']);
        $this->assertSame('2026-07-01', $filters['from']);
        $this->assertSame('2026-09-30', $filters['to']);
    }

    public function test_creating_validates_name_and_filters(): void
    {
        $this->actingAs($this->superAdmin)->post(route('super-admin.reports.store'), [
            'name' => '',
            'filters' => ['range' => 'custom', 'metrics' => ['bogus']],
        ])->assertSessionHasErrors(['name', 'filters.from', 'filters.to', 'filters.metrics.0']);

        $this->assertSame(0, SuperAdminReport::query()->count());
    }

    public function test_a_super_admin_can_update_a_report(): void
    {
        $report = SuperAdminReport::factory()->create(['created_by' => $this->superAdmin->id]);

        $this->actingAs($this->superAdmin)->put(route('super-admin.reports.update', $report), [
            'name' => 'Renamed',
            'filters' => ['range' => '7d', 'metrics' => ['ai']],
        ])->assertRedirect(route('super-admin.reports.show', $report));

        $report->refresh();
        $this->assertSame('Renamed', $report->name);
        $this->assertSame('7d', $report->filters['range']);
        $this->assertSame(['ai'], $report->filters['metrics']);
    }

    public function test_a_super_admin_can_delete_a_report(): void
    {
        $report = SuperAdminReport::factory()->create(['created_by' => $this->superAdmin->id]);

        $this->actingAs($this->superAdmin)
            ->delete(route('super-admin.reports.destroy', $report))
            ->assertRedirect(route('super-admin.reports.index'));

        $this->assertModelMissing($report);
    }

    public function test_running_a_report_returns_exactly_what_the_analytics_action_produces(): void
    {
        $organization = $this->seedActivity();
        $filters = ['range' => '30d', 'from' => null, 'to' => null, 'org_ids' => [$organization->id], 'metrics' => []];
        $report = SuperAdminReport::factory()->create(['filters' => $filters, 'created_by' => $this->superAdmin->id]);

        $direct = app(GetPlatformAnalyticsSummaryAction::class)(AnalyticsFilters::fromArray($filters));
        $viaReport = app(RunReportAction::class)($report);

        $this->assertSame($direct, $viaReport);
        $this->assertSame(1, $viaReport['posts']['published']);
        $this->assertSame([['currency' => 'GBP', 'total' => 1200.0]], $viaReport['revenue']['invoiced']);
        $this->assertNotNull($report->fresh()->last_run_at);
    }

    public function test_the_run_view_renders_the_report_and_its_summary(): void
    {
        $this->seedActivity();
        $report = SuperAdminReport::factory()->create([
            'name' => 'Weekly pulse',
            'filters' => ['range' => '7d'],
            'created_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.reports.show', $report))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/reports/show')
                ->where('report.name', 'Weekly pulse')
                ->where('report.filters.range', '7d')
                ->where('summary.filters.from', '2026-10-11')
                ->where('summary.posts.published', 1));
    }

    public function test_the_index_lists_reports_with_organization_names(): void
    {
        $organization = Organization::factory()->create(['name' => 'Northlight Studio']);
        SuperAdminReport::factory()->create([
            'filters' => ['range' => '30d', 'org_ids' => [$organization->id]],
            'created_by' => $this->superAdmin->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.reports.index'))
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/reports/index')
                ->has('reports', 1)
                ->where('reports.0.organization_names', ['Northlight Studio']));
    }

    public function test_the_builder_prefills_filters_from_the_analytics_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('super-admin.reports.create', ['range' => '90d', 'metrics' => ['posts']]))
            ->assertInertia(fn ($page) => $page
                ->component('super-admin/reports/edit')
                ->where('report', null)
                ->where('defaults.filters.range', '90d')
                ->where('defaults.filters.metrics', ['posts']));
    }

    public function test_a_report_can_be_exported_as_csv(): void
    {
        $this->seedActivity();
        $report = SuperAdminReport::factory()->create([
            'name' => 'Board pack',
            'filters' => ['range' => '30d', 'metrics' => ['posts', 'revenue']],
            'created_by' => $this->superAdmin->id,
        ]);

        $response = $this->actingAs($this->superAdmin)->get(route('super-admin.reports.export', $report));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
        $this->assertStringContainsString('board-pack-2026-10-17.csv', (string) $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $this->assertStringContainsString('posts,published,1,', $csv);
        $this->assertStringContainsString('revenue,invoiced,1200,GBP', $csv);
        $this->assertStringNotContainsString('organizations,total', $csv);
    }
}
