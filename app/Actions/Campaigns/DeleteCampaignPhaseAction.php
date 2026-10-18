<?php

namespace App\Actions\Campaigns;

use App\Models\CampaignPhase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-3.1 — remove a phase. Its deliverables stay in the plan, unphased.
 */
class DeleteCampaignPhaseAction
{
    public function __invoke(CampaignPhase $phase, ?User $user): void
    {
        DB::transaction(function () use ($phase, $user) {
            $campaign = $phase->campaign;
            $phase->deliverables()->update(['campaign_phase_id' => null]);
            $phase->delete();
            $campaign->recordActivity('phases.updated', $user, meta: ['deleted_phase_id' => $phase->id]);
        });

        Log::info('Campaign phase deleted', [
            'campaign_id' => $phase->campaign_id,
            'phase_id' => $phase->id,
            'user_id' => $user?->id,
        ]);
    }
}
