<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateCampaignAction
{
    /**
     * Create a new campaign for the given client.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, array $data): Campaign
    {
        return DB::transaction(function () use ($client, $data) {
            $campaign = Campaign::create([
                ...$data,
                'org_id' => $client->org_id,
                'client_id' => $client->id,
            ]);

            Log::info('Campaign created', [
                'client_id' => $client->id,
                'campaign_id' => $campaign->id,
            ]);

            return $campaign;
        });
    }
}
