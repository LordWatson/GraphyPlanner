<?php

namespace App\Actions\Analytics;

use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\OrganizationReportAttributes;
use Illuminate\Support\Facades\Log;

/**
 * Step 17.6 — renames a saved report, changes its filters and (Step 17.8) its email schedule.
 */
class UpdateOrganizationReportAction
{
    /**
     * @param  array{name: string, description?: string|null, filters: array<string, mixed>, schedule?: string|null, recipient_ids?: list<int>}  $data
     */
    public function __invoke(OrganizationReport $report, array $data, User $actor): OrganizationReport
    {
        $attributes = OrganizationReportAttributes::attributes($report->organization, $data);
        $wasScheduled = $report->schedule;

        $report->fill($attributes);

        // A new or changed schedule starts its own period: the next daily run may send it.
        if ($report->schedule !== $wasScheduled) {
            $report->last_sent_at = null;
        }

        $report->save();

        Log::info('Organization report updated', [
            'org_id' => $report->org_id,
            'report_id' => $report->id,
            'user_id' => $actor->id,
            'schedule' => $report->schedule?->value,
            'recipient_count' => count($report->recipientIds()),
        ]);

        return $report;
    }
}
