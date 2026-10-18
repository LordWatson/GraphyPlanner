<?php

namespace Tests\Feature\Analytics;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Models\OrganizationReport;
use App\Support\Analytics\AnalyticsCsv;
use App\Support\Analytics\OrganizationAnalyticsCsv;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Analytics\Concerns\BuildsAnalyticsFixtures;
use Tests\TestCase;

/**
 * Step 17.7 — CSV and branded PDF exports: per-section CSV content, role-scoped exports (a
 * Designer's file has no billing), filenames, and PDF responses for the page and saved reports.
 */
class AnalyticsExportTest extends TestCase
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
     * @return list<list<string|null>>
     */
    private function parse(string $csv): array
    {
        return array_map(fn (string $line) => str_getcsv($line, escape: '\\'), array_filter(explode("\n", trim($csv)), fn ($line) => $line !== ''));
    }

    public function test_the_csv_has_a_row_block_per_visible_section(): void
    {
        $response = $this->actingAs($this->owner)->get(route('analytics.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('brightside-studio-analytics-2026-10-17.csv', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $rows = $this->parse($csv);

        $this->assertSame(['Organization', 'Brightside Studio'], $rows[0]);
        $this->assertSame(['Range', '30d', '2026-09-18', '2026-10-17'], $rows[1]);
        $this->assertSame(['Timezone', 'Europe/London'], $rows[2]);

        $this->assertStringContainsString("clients,health_red,1,\n", $csv);
        $this->assertStringContainsString("posts,published,2,\n", $csv);
        $this->assertStringContainsString("posts,pipeline_waiting_client,2,\n", $csv);
        $this->assertStringContainsString("posts,published_instagram,2,\n", $csv);
        $this->assertStringContainsString("approvals,client_turnaround_median_hours,24,\n", $csv);
        $this->assertStringContainsString("approvals,oldest_waiting_client_name,Bolt,\n", $csv);
        $this->assertStringContainsString("team,client_decisions,3,\n", $csv);
        // Money: one row per currency, never summed.
        $this->assertStringContainsString("revenue,invoiced,2000,AED\n", $csv);
        $this->assertStringContainsString("revenue,invoiced,1500,GBP\n", $csv);
        $this->assertStringContainsString("revenue,retainers_monthly,2000,GBP\n", $csv);
        $this->assertStringContainsString("ai,images_succeeded,4,\n", $csv);
        $this->assertStringContainsString("ai,cost_estimate,0.14,\n", $csv);
        // Tables.
        $this->assertStringContainsString('"Team member",Role,"Posts created","Status changes","Sent to client",Scheduled'."\n", $csv);
        $this->assertStringContainsString("\"Sam Strategist\",Strategist,3,5,3,1\n", $csv);
        $this->assertStringContainsString('"Client needing attention",Health,Reason'."\nCrest,red,", $csv);
        $this->assertStringContainsString('Period,Created,Published,Failed,"Sent for approval",Approved,"Invoiced (GBP)"', $csv);
        $this->assertStringContainsString('Client,Health,Published,Scheduled,"Waiting on client","Invoiced (GBP)"', $csv);
        $this->assertStringContainsString("Acme,green,1,1,1,1500\n", $csv);
        $this->assertStringNotContainsString('Foreign Client', $csv);
    }

    public function test_a_designers_export_has_no_revenue_rows_and_no_cost(): void
    {
        $csv = $this->actingAs($this->designer)->get(route('analytics.export'))->streamedContent();

        $this->assertStringNotContainsString('revenue,', $csv);
        $this->assertStringNotContainsString('Invoiced', $csv);
        $this->assertStringNotContainsString('AED', $csv);
        $this->assertStringNotContainsString('cost_estimate', $csv);
        $this->assertStringContainsString("posts,published,2,\n", $csv);
        $this->assertStringContainsString("Acme,green,1,1,1\n", $csv);
    }

    public function test_a_strategists_export_has_revenue_but_no_ai_cost(): void
    {
        $csv = $this->actingAs($this->strategist)->get(route('analytics.export'))->streamedContent();

        $this->assertStringContainsString('revenue,invoiced,2000,AED', $csv);
        $this->assertStringNotContainsString('cost_estimate', $csv);
    }

    public function test_the_csv_flattener_matches_the_summary_it_was_given(): void
    {
        $summary = app(GetOrganizationAnalyticsSummaryAction::class)(
            $this->org,
            OrganizationAnalyticsFilters::fromArray(['client_ids' => [$this->acme->id], 'metrics' => ['posts']], $this->org),
            $this->owner,
        );

        $csv = AnalyticsCsv::toString(OrganizationAnalyticsCsv::rows($summary));

        $this->assertStringContainsString('Client,Acme,green,', $csv);
        $this->assertStringContainsString("posts,published,1,\n", $csv);
        $this->assertStringNotContainsString('approvals,', $csv);
        $this->assertStringContainsString("Campaign,Client,Published,Scheduled,\"Waiting on client\"\n\"Autumn launch\",Acme,1,0,0", $csv);
    }

    public function test_a_saved_report_exports_as_csv_and_pdf_with_its_slugged_name(): void
    {
        $report = OrganizationReport::factory()->create(['org_id' => $this->org->id, 'created_by' => $this->owner->id, 'name' => 'Board Pack: Q4!']);

        $csv = $this->actingAs($this->viewer)->get(route('analytics.reports.export', $report));
        $csv->assertOk();
        $this->assertStringContainsString('board-pack-q4-2026-10-17.csv', (string) $csv->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('revenue,', $csv->streamedContent());

        $pdf = $this->actingAs($this->owner)->get(route('analytics.reports.pdf', $report));
        $pdf->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('board-pack-q4-2026-10-17.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_the_current_view_exports_as_a_pdf_for_every_role(): void
    {
        foreach ([$this->owner, $this->designer] as $user) {
            $response = $this->actingAs($user)->get(route('analytics.pdf', ['client_ids' => [$this->acme->id]]));

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringContainsString('acme-analytics-2026-10-17.pdf', (string) $response->headers->get('Content-Disposition'));
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }
    }

    public function test_the_pdf_view_only_draws_visible_sections(): void
    {
        $summary = fn ($viewer) => app(GetOrganizationAnalyticsSummaryAction::class)(
            $this->org,
            OrganizationAnalyticsFilters::fromArray([], $this->org),
            $viewer,
        );

        $designer = view('pdf.analytics-report', ['title' => 'Studio analytics', 'description' => null, 'summary' => $summary($this->designer), 'generatedAt' => now()])->render();
        $owner = view('pdf.analytics-report', ['title' => 'Studio analytics', 'description' => null, 'summary' => $summary($this->owner), 'generatedAt' => now()])->render();

        $this->assertStringNotContainsString('05 &mdash; Billing', $designer);
        $this->assertStringNotContainsString('Estimated cost', $designer);
        $this->assertStringContainsString('04 &mdash; Team workload', $designer);
        $this->assertStringContainsString('05 &mdash; Billing', $owner);
        $this->assertStringContainsString('Estimated cost', $owner);
        $this->assertStringContainsString('Marketing brighter together.', $owner);
    }
}
