<?php

namespace App\Actions\Analytics;

use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use Carbon\CarbonImmutable;

/**
 * Step 17.6 — re-runs a saved report: rebuilds its stored filters (relative presets resolve
 * against today in the org's timezone; deleted clients/campaigns drop out quietly) and calls
 * `GetOrganizationAnalyticsSummaryAction` with the **current viewer**, so permissions apply when
 * the report runs, not when it was saved. Then stamps `last_run_at`.
 */
class RunOrganizationReportAction
{
    public function __construct(private readonly GetOrganizationAnalyticsSummaryAction $summary) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(OrganizationReport $report, User $viewer, ?CarbonImmutable $now = null): array
    {
        $organization = $report->organization;

        $result = ($this->summary)($organization, $this->filters($report, $now), $viewer);

        $report->forceFill(['last_run_at' => now()])->saveQuietly();

        return $result;
    }

    public function filters(OrganizationReport $report, ?CarbonImmutable $now = null): OrganizationAnalyticsFilters
    {
        return OrganizationAnalyticsFilters::fromArray($report->filters, $report->organization, $now);
    }
}
