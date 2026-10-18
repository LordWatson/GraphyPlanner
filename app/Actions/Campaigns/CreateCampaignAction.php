<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateCampaignAction
{
    /**
     * Create a new campaign for the given client and open its activity log.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, array $data, ?User $user = null): Campaign
    {
        return DB::transaction(function () use ($client, $data, $user) {
            $campaign = Campaign::create([
                ...$data,
                'status' => $data['status'] ?? CampaignStatus::Planning,
                'org_id' => $client->org_id,
                'client_id' => $client->id,
            ]);

            $campaign->recordActivity('campaign.created', $user, to: $campaign->status->value);

            Log::info('Campaign created', [
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'campaign_id' => $campaign->id,
                'user_id' => $user?->id,
            ]);

            return $campaign;
        });
    }
}
