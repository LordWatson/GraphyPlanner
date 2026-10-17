<?php

namespace App\Actions\SuperAdmin;

use App\Models\SuperAdminReport;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.5 — deletes a saved report configuration (no analytics data is touched).
 */
class DeleteReportAction
{
    public function __invoke(SuperAdminReport $report, User $actor): void
    {
        $reportId = $report->id;

        $report->delete();

        Log::info('Super admin report deleted', [
            'report_id' => $reportId,
            'user_id' => $actor->id,
        ]);
    }
}
