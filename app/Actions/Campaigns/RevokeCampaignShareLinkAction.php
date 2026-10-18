<?php

namespace App\Actions\Campaigns;

use App\Models\CampaignShareLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Campaigns enhancement C-5.4 — switch a preview link off for good. Idempotent.
 */
class RevokeCampaignShareLinkAction
{
    public function __invoke(CampaignShareLink $link, User $user): void
    {
        if ($link->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($link, $user) {
            $link->update(['revoked_at' => now()]);
            $link->campaign->recordActivity('share_link.revoked', $user, meta: ['share_link_id' => $link->id]);
        });

        Log::info('Campaign share link revoked', [
            'campaign_id' => $link->campaign_id,
            'share_link_id' => $link->id,
            'user_id' => $user->id,
        ]);
    }
}
