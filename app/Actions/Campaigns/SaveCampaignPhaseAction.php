<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignPhase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-3.1 — create or update one phase. New phases go to the end of the order. Date bounds
 * are validated by `SaveCampaignPhaseRequest` before this runs.
 */
class SaveCampaignPhaseAction
{
    /**
     * @param  array<string, mixed>  $data  validated by `SaveCampaignPhaseRequest`
     */
    public function __invoke(Campaign $campaign, array $data, ?User $user, ?CampaignPhase $phase = null): CampaignPhase
    {
        return DB::transaction(function () use ($campaign, $data, $user, $phase) {
            if ($phase === null) {
                $phase = $campaign->phases()->create([
                    ...$data,
                    'sort' => (int) $campaign->phases()->max('sort') + 1,
                ]);
            } else {
                $phase->update($data);
            }

            $campaign->recordActivity('phases.updated', $user, meta: ['phase_id' => $phase->id]);

            Log::info('Campaign phase saved', [
                'campaign_id' => $campaign->id,
                'phase_id' => $phase->id,
                'user_id' => $user?->id,
            ]);

            return $phase;
        });
    }
}
