<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignMetric;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-7.1 — replace a campaign's KPI targets in one transaction, keeping the given order.
 */
class UpdateCampaignKpisAction
{
    /**
     * @param  list<array{metric: CampaignMetric, target: float}>  $targets
     */
    public function __invoke(Campaign $campaign, array $targets, ?User $user): void
    {
        DB::transaction(function () use ($campaign, $targets, $user) {
            $campaign->kpis()->delete();

            foreach ($targets as $index => $target) {
                $campaign->kpis()->create([
                    'metric' => $target['metric'],
                    'target' => $target['target'],
                    'sort' => $index + 1,
                ]);
            }

            $campaign->recordActivity('kpis.updated', $user, meta: ['metrics' => array_map(fn ($target) => $target['metric']->value, $targets)]);
        });

        Log::info('Campaign KPI targets updated', [
            'campaign_id' => $campaign->id,
            'user_id' => $user?->id,
            'count' => count($targets),
        ]);
    }
}
