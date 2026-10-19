<?php

namespace App\Actions\Campaigns;

use App\Jobs\PlanCampaignJob;
use App\Models\Campaign;
use App\Models\User;
use App\Services\Campaigns\CampaignPlanRuns;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step C-3.3 — starts a "Plan with AI" run: refuses straight away when the campaign has no
 * platform to plan for (so that message still reaches the person instantly), otherwise records a
 * pending run and queues `PlanCampaignJob`. The planner polls `CampaignPlanRuns` for the outcome.
 */
class StartCampaignPlanAction
{
    public function __construct(
        private readonly PlanCampaignAction $plan,
        private readonly CampaignPlanRuns $runs,
    ) {}

    public function __invoke(Campaign $campaign, User $user): string
    {
        if ($this->plan->allowedPlatforms($campaign) === []) {
            throw ValidationException::withMessages(['platforms' => 'This client has no connected accounts on this campaign’s platforms. Connect one, then plan.']);
        }

        $runId = $this->runs->start($campaign, $user);

        Log::info('AI campaign plan queued', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'user_id' => $user->id,
            'run_id' => $runId,
        ]);

        PlanCampaignJob::dispatch($campaign->id, $user->id, $runId);

        return $runId;
    }
}
