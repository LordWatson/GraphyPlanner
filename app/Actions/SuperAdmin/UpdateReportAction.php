<?php

namespace App\Actions\SuperAdmin;

use App\Models\SuperAdminReport;
use App\Models\User;
use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.5 — renames a saved report and/or changes its filters.
 */
class UpdateReportAction
{
    /**
     * @param  array{name: string, description?: string|null, filters: array<string, mixed>}  $data
     */
    public function __invoke(SuperAdminReport $report, array $data, User $actor): SuperAdminReport
    {
        $report->update([
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'filters' => AnalyticsFilters::fromArray($data['filters'])->toStoredArray(),
        ]);

        Log::info('Super admin report updated', [
            'report_id' => $report->id,
            'user_id' => $actor->id,
        ]);

        return $report;
    }
}
