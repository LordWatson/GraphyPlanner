<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-7.3 — save the wrap report's commentary. Editing a shared report doesn't unshare it or
 * touch its frozen KPI snapshot; the client sees the new words at the next share.
 */
class UpdateCampaignReportAction
{
    /**
     * @param  array{summary?: string|null, highlights?: list<string>, learnings?: string|null, next_steps?: string|null}  $data
     */
    public function __invoke(Campaign $campaign, array $data, User $user): CampaignReport
    {
        return DB::transaction(function () use ($campaign, $data, $user) {
            /** @var CampaignReport $report */
            $report = $campaign->report()->lockForUpdate()->first() ?? $campaign->report()->make();
            $report->fill($data)->save();

            $campaign->recordActivity('report.updated', $user);

            Log::info('Campaign wrap report saved', ['campaign_id' => $campaign->id, 'report_id' => $report->id, 'user_id' => $user->id]);

            return $report;
        });
    }
}
