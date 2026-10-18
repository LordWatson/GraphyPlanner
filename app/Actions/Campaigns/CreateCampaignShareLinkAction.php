<?php

namespace App\Actions\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignShareLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Campaigns enhancement C-5.4 — mint a read-only preview link. The plain token is returned once
 * and never stored (only its SHA-256), like `CreateReviewTokenAction`.
 */
class CreateCampaignShareLinkAction
{
    /**
     * @return array{token: string, link: CampaignShareLink}
     */
    public function __invoke(Campaign $campaign, User $user, int $days = CampaignShareLink::DEFAULT_DAYS): array
    {
        $token = Str::random(64);

        $link = DB::transaction(function () use ($campaign, $user, $days, $token) {
            $link = $campaign->shareLinks()->create([
                'created_by' => $user->id,
                'token_hash' => CampaignShareLink::hash($token),
                'expires_at' => now()->addDays(max(1, min($days, CampaignShareLink::MAX_DAYS))),
            ]);

            $campaign->recordActivity('share_link.created', $user, meta: ['share_link_id' => $link->id, 'expires_at' => $link->expires_at->toIso8601String()]);

            return $link;
        });

        Log::info('Campaign share link created', [
            'campaign_id' => $campaign->id,
            'org_id' => $campaign->org_id,
            'share_link_id' => $link->id,
            'user_id' => $user->id,
        ]);

        return ['token' => $token, 'link' => $link];
    }
}
