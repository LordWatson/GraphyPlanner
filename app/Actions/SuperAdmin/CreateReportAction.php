<?php

namespace App\Actions\SuperAdmin;

use App\Models\SuperAdminReport;
use App\Models\User;
use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.5 — saves a named analytics configuration. Filters are normalised through
 * `AnalyticsFilters` so what's stored is exactly what `RunReportAction` will read back.
 */
class CreateReportAction
{
    /**
     * @param  array{name: string, description?: string|null, filters: array<string, mixed>}  $data
     */
    public function __invoke(array $data, User $actor): SuperAdminReport
    {
        $report = SuperAdminReport::create([
            'name' => trim($data['name']),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'filters' => AnalyticsFilters::fromArray($data['filters'])->toStoredArray(),
            'created_by' => $actor->id,
        ]);

        Log::info('Super admin report created', [
            'report_id' => $report->id,
            'user_id' => $actor->id,
        ]);

        return $report;
    }
}
