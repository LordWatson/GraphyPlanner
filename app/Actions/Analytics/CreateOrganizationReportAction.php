<?php

namespace App\Actions\Analytics;

use App\Models\Organization;
use App\Models\OrganizationReport;
use App\Models\User;
use App\Support\Analytics\OrganizationReportAttributes;
use Illuminate\Support\Facades\Log;

/**
 * Step 17.6 — saves a named analytics view for the whole organization.
 */
class CreateOrganizationReportAction
{
    /**
     * @param  array{name: string, description?: string|null, filters: array<string, mixed>, schedule?: string|null, recipient_ids?: list<int>}  $data
     */
    public function __invoke(Organization $organization, array $data, User $actor): OrganizationReport
    {
        $report = OrganizationReport::create([
            ...OrganizationReportAttributes::attributes($organization, $data),
            'org_id' => $organization->id,
            'created_by' => $actor->id,
        ]);

        Log::info('Organization report created', [
            'org_id' => $organization->id,
            'report_id' => $report->id,
            'user_id' => $actor->id,
            'schedule' => $report->schedule?->value,
            'recipient_count' => count($report->recipientIds()),
        ]);

        return $report;
    }
}
