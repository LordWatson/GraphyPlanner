<?php

namespace App\Actions\SuperAdmin;

use App\Models\SuperAdminReport;
use App\Support\SuperAdmin\AnalyticsFilters;
use Carbon\CarbonImmutable;

/**
 * Step 15.5 — re-runs a saved report by handing its stored filters straight to
 * `GetPlatformAnalyticsSummaryAction` (no duplicated aggregation logic), then stamps
 * `last_run_at`. Relative ranges (`30d`, `ytd`…) resolve against today on every run.
 */
class RunReportAction
{
    public function __construct(private readonly GetPlatformAnalyticsSummaryAction $summary) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(SuperAdminReport $report, ?CarbonImmutable $now = null): array
    {
        $result = ($this->summary)(AnalyticsFilters::fromArray($report->filters, $now));

        $report->forceFill(['last_run_at' => now()])->saveQuietly();

        return $result;
    }
}
