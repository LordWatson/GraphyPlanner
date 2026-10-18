<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignBillingType;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateCampaignAction
{
    /**
     * Update an existing campaign. Status is never changed here (Step C-0.3 —
     * `TransitionCampaignStatusAction` owns it); the names of the changed fields are logged.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Campaign $campaign, array $data, ?User $user = null): Campaign
    {
        unset($data['status']);

        // Step C-8.1: only a fixed fee keeps an amount.
        if (array_key_exists('billing_type', $data) && $data['billing_type'] !== CampaignBillingType::FixedFee->value) {
            $data['fee_amount'] = null;
        }

        return DB::transaction(function () use ($campaign, $data, $user) {
            $campaign->fill($data);
            $changed = array_keys($campaign->getDirty());

            if ($changed === []) {
                return $campaign;
            }

            $campaign->save();
            $campaign->recordActivity('campaign.updated', $user, meta: ['fields' => $changed]);

            Log::info('Campaign updated', [
                'org_id' => $campaign->org_id,
                'client_id' => $campaign->client_id,
                'campaign_id' => $campaign->id,
                'user_id' => $user?->id,
                'fields' => $changed,
            ]);

            return $campaign;
        });
    }
}
