<?php

namespace App\Actions\Campaigns;

use App\Enums\BriefStatus;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-2.1 — create or edit a campaign's brief.
 *
 * A signed brief can't change silently: editing the content of a brief that is sent, approved
 * or awaiting changes puts it back to draft (clearing the approval) and logs `brief.reopened`.
 * Saving identical content changes nothing.
 */
class UpdateCampaignBriefAction
{
    /**
     * @param  array<string, mixed>  $content  any of `CampaignBrief::CONTENT_FIELDS`
     */
    public function __invoke(Campaign $campaign, array $content, ?User $user): CampaignBrief
    {
        return DB::transaction(function () use ($campaign, $content, $user) {
            /** @var CampaignBrief $brief */
            $brief = $campaign->brief()->lockForUpdate()->first() ?? $campaign->brief()->make();

            $brief->fill(array_intersect_key($content, array_flip(CampaignBrief::CONTENT_FIELDS)));

            if ($brief->exists && ! $brief->isDirty()) {
                return $brief;
            }

            $previous = $brief->exists ? $brief->getOriginal('status') : null;
            $reopened = $previous instanceof BriefStatus && $previous !== BriefStatus::Draft;

            if ($reopened) {
                $brief->status = BriefStatus::Draft;
                $brief->approved_at = null;
                $brief->approved_by = null;
            }

            $brief->updated_by = $user?->id;
            $brief->save();

            $campaign->recordActivity($reopened ? 'brief.reopened' : 'brief.updated', $user, $reopened ? $previous->value : null, $reopened ? BriefStatus::Draft->value : null);

            Log::info($reopened ? 'Campaign brief reopened by an edit' : 'Campaign brief saved', [
                'campaign_id' => $campaign->id,
                'brief_id' => $brief->id,
                'user_id' => $user?->id,
                'previous_status' => $previous?->value,
            ]);

            return $brief;
        });
    }
}
