<?php

namespace App\Actions\Campaigns;

use App\Models\Asset;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-4.1 — the campaign's asset kit is the client's assets tagged with this campaign. Adding
 * from the library tags them (an asset belongs to at most one campaign, so it moves kits);
 * removing untags — the asset itself is never deleted.
 */
class UpdateCampaignAssetKitAction
{
    /**
     * @param  list<int>  $assetIds  already validated to belong to the campaign's client
     */
    public function add(Campaign $campaign, array $assetIds, User $user): int
    {
        $count = DB::transaction(function () use ($campaign, $assetIds, $user) {
            $count = Asset::query()
                ->where('client_id', $campaign->client_id)
                ->whereIn('id', $assetIds)
                ->update(['campaign_id' => $campaign->id]);

            $campaign->recordActivity('assets.updated', $user, meta: ['added' => $assetIds]);

            return $count;
        });

        Log::info('Assets added to campaign kit', ['campaign_id' => $campaign->id, 'user_id' => $user->id, 'count' => $count]);

        return $count;
    }

    public function remove(Campaign $campaign, Asset $asset, User $user): void
    {
        DB::transaction(function () use ($campaign, $asset, $user) {
            $asset->update(['campaign_id' => null]);
            $campaign->recordActivity('assets.updated', $user, meta: ['removed' => [$asset->id]]);
        });

        Log::info('Asset removed from campaign kit', ['campaign_id' => $campaign->id, 'asset_id' => $asset->id, 'user_id' => $user->id]);
    }
}
