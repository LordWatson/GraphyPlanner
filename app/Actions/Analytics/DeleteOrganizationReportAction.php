<?php

namespace App\Actions\Analytics;

use App\Models\OrganizationReport;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step 17.6 — deletes a saved report configuration (no analytics data is touched).
 */
class DeleteOrganizationReportAction
{
    public function __invoke(OrganizationReport $report, User $actor): void
    {
        $context = ['org_id' => $report->org_id, 'report_id' => $report->id, 'user_id' => $actor->id];

        $report->delete();

        Log::info('Organization report deleted', $context);
    }
}
