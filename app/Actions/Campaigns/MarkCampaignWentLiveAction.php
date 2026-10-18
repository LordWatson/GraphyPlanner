<?php

namespace App\Actions\Campaigns;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CampaignWentLive;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step C-6.3 — when a campaign's first post publishes, log `campaign.went_live` and tell the
 * owner (or the Owners/Strategists when it has none). The campaign row is locked so two posts
 * publishing at once still fire it exactly once.
 */
class MarkCampaignWentLiveAction
{
    public function __invoke(Post $post): void
    {
        if ($post->campaign_id === null) {
            return;
        }

        $campaign = DB::transaction(function () use ($post) {
            /** @var Campaign|null $campaign */
            $campaign = Campaign::query()->withoutOrgScope()->whereKey($post->campaign_id)->lockForUpdate()->first();

            if ($campaign === null || $campaign->activityLogs()->where('event', 'campaign.went_live')->exists()) {
                return null;
            }

            $campaign->recordActivity('campaign.went_live', null, meta: ['post_id' => $post->id]);

            return $campaign;
        });

        if ($campaign === null) {
            return;
        }

        $recipients = $campaign->owner_id !== null
            ? User::query()->whereKey($campaign->owner_id)->get()
            : User::query()->where('org_id', $campaign->org_id)->whereIn('role', [Role::Owner, Role::Strategist])->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new CampaignWentLive($campaign));
        }

        Log::info('Campaign went live', ['campaign_id' => $campaign->id, 'post_id' => $post->id, 'recipients' => $recipients->count()]);
    }
}
