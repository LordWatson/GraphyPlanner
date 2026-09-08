<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateCampaignAction
{
    /**
     * Update an existing campaign.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Campaign $campaign, array $data): Campaign
    {
        return DB::transaction(function () use ($campaign, $data) {
            $campaign->update($data);

            Log::info('Campaign updated', [
                'client_id' => $campaign->client_id,
                'campaign_id' => $campaign->id,
            ]);

            return $campaign;
        });
    }
}
