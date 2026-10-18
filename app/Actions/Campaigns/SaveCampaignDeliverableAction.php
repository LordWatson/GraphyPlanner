<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\User;
use App\Notifications\CampaignDeliverableAssigned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-3.2 — create or update one planned deliverable. Step C-4.4: when the assignee changes to
 * someone other than the actor, that person (and only that person) is notified after commit.
 */
class SaveCampaignDeliverableAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Campaign $campaign, array $data, ?User $user, ?CampaignDeliverable $deliverable = null): CampaignDeliverable
    {
        return DB::transaction(function () use ($campaign, $data, $user, $deliverable) {
            $previousAssignee = $deliverable?->assignee_id;

            if ($deliverable === null) {
                $deliverable = $campaign->deliverables()->create([
                    ...$data,
                    'sort' => (int) $campaign->deliverables()->max('sort') + 1,
                ]);
                $campaign->recordActivity('deliverables.added', $user, meta: ['deliverable_ids' => [$deliverable->id]]);
            } else {
                $deliverable->update($data);
            }

            $assignee = $deliverable->assignee_id;

            if ($assignee !== null && $assignee !== $previousAssignee && $assignee !== $user?->id) {
                DB::afterCommit(function () use ($deliverable) {
                    $deliverable->loadMissing('assignee', 'campaign');
                    $deliverable->assignee?->notify(new CampaignDeliverableAssigned($deliverable));
                });
            }

            Log::info('Campaign deliverable saved', [
                'campaign_id' => $campaign->id,
                'deliverable_id' => $deliverable->id,
                'user_id' => $user?->id,
            ]);

            return $deliverable;
        });
    }
}
