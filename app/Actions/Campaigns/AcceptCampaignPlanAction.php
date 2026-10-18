<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-3.3 — turn the suggestions a person ticked in the review drawer into planned
 * deliverables, all in one transaction. The request has already re-validated every field (the
 * suggestions came back through the browser, so they're treated as user input).
 */
class AcceptCampaignPlanAction
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<CampaignDeliverable>
     */
    public function __invoke(Campaign $campaign, array $items, User $user): array
    {
        $created = DB::transaction(function () use ($campaign, $items, $user) {
            $sort = (int) $campaign->deliverables()->max('sort');
            $created = [];

            foreach ($items as $item) {
                $created[] = $campaign->deliverables()->create([
                    'campaign_phase_id' => $item['campaign_phase_id'] ?? null,
                    'title' => $item['title'],
                    'platform' => $item['platform'],
                    'format' => $item['format'] ?? null,
                    'pillar' => $item['pillar'] ?? null,
                    'planned_for' => $item['planned_for'],
                    'planned_time' => $item['planned_time'] ?? null,
                    'notes' => $item['notes'] ?? null,
                    'sort' => ++$sort,
                ]);
            }

            $campaign->recordActivity('deliverables.added', $user, meta: ['count' => count($created), 'source' => 'ai_planner']);

            return $created;
        });

        Log::info('AI campaign plan accepted', [
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'count' => count($created),
        ]);

        return $created;
    }
}
