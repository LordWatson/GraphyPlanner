<?php

namespace App\Actions\Analytics;

use App\Models\ClientReport;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-3.3 — save the agency's commentary on a draft report. Published reports are locked:
 * the client has already seen (and been emailed) that version.
 */
class UpdateClientReportAction
{
    public function __invoke(ClientReport $report, ?string $commentary, User $user): ClientReport
    {
        if ($report->isPublished()) {
            throw ValidationException::withMessages(['commentary' => 'This report has been published, so it can no longer be changed.']);
        }

        $commentary = $commentary === null ? null : trim($commentary);
        $report->update(['commentary' => $commentary === '' ? null : $commentary]);

        Log::info('Client report commentary saved', [
            'org_id' => $report->org_id,
            'client_report_id' => $report->id,
            'user_id' => $user->id,
        ]);

        return $report;
    }
}
