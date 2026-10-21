<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignReportStatus;
use App\Enums\Role;
use App\Mail\CampaignReportSharedMail;
use App\Models\Campaign;
use App\Models\CampaignReport;
use App\Models\User;
use App\Services\CampaignKpiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Step C-7.3 — "Share with client": freeze the KPI results as they stand now into
 * `kpi_snapshot` and (M-3.1) the engagement into `engagement_snapshot` (so the numbers the client
 * saw never change when later data arrives), bump the version, publish the report to the portal and, after commit, email the client's portal contacts
 * the branded PDF. Never includes fees or invoices.
 */
class ShareCampaignReportAction
{
    public function __construct(private readonly CampaignKpiService $kpis) {}

    public function __invoke(Campaign $campaign, User $user): CampaignReport
    {
        $report = DB::transaction(function () use ($campaign, $user) {
            /** @var CampaignReport|null $report */
            $report = $campaign->report()->lockForUpdate()->first();

            if ($report === null || blank($report->summary)) {
                throw ValidationException::withMessages(['summary' => 'Write a summary before sharing the report.']);
            }

            $report->update([
                'kpi_snapshot' => $this->snapshot($campaign),
                // Step M-3.1: engagement freezes with the KPIs (null when nothing is measured yet).
                'engagement_snapshot' => $this->kpis->engagementSnapshot($campaign),
                'status' => CampaignReportStatus::Shared,
                'version' => $report->version + 1,
                'shared_at' => now(),
                'shared_by' => $user->id,
            ]);

            $campaign->recordActivity('report.shared', $user, meta: ['version' => $report->version]);

            DB::afterCommit(function () use ($campaign, $report) {
                $contacts = User::query()->where('client_id', $campaign->client_id)->where('role', Role::ClientReviewer)->get();

                foreach ($contacts as $contact) {
                    Mail::to($contact->email)->queue(new CampaignReportSharedMail($campaign, $report, $contact));
                }
            });

            return $report;
        });

        Log::info('Campaign wrap report shared', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'version' => $report->version,
            'user_id' => $user->id,
        ]);

        return $report;
    }

    /**
     * Only the fields a client should see; no billing metric exists in the KPI set by design.
     *
     * @return list<array<string, mixed>>
     */
    private function snapshot(Campaign $campaign): array
    {
        return array_map(fn (array $row) => [
            'metric' => $row['metric'],
            'label' => $row['label'],
            'unit' => $row['unit'],
            'lower_is_better' => $row['lower_is_better'],
            'target' => $row['target'],
            'actual' => $row['actual'],
            'available' => $row['available'],
            'note' => $row['note'],
            'met' => $row['met'],
            'progress' => $row['progress'],
        ], $this->kpis->results($campaign));
    }
}
