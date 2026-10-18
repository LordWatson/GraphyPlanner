<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Step C-3.1 — set the phase order in one transaction. The given ids must be exactly the
 * campaign's phases (validated by the request; re-checked here so a partial order can never be
 * written).
 */
class ReorderCampaignPhasesAction
{
    /**
     * @param  list<int>  $orderedIds
     */
    public function __invoke(Campaign $campaign, array $orderedIds, ?User $user): void
    {
        DB::transaction(function () use ($campaign, $orderedIds, $user) {
            $existing = $campaign->phases()->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $given = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($existing !== $given) {
                throw new InvalidArgumentException('The phase order must list every phase of the campaign exactly once.');
            }

            foreach ($orderedIds as $index => $id) {
                $campaign->phases()->whereKey($id)->update(['sort' => $index + 1]);
            }

            $campaign->recordActivity('phases.updated', $user, meta: ['reordered' => true]);
        });

        Log::info('Campaign phases reordered', [
            'campaign_id' => $campaign->id,
            'user_id' => $user?->id,
            'phase_count' => count($orderedIds),
        ]);
    }
}
