<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\ConnectionStatus;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Red: a scheduled post in the campaign targets a social account whose token has expired, so it
 * will fail when it fires unless someone reconnects the account.
 */
class ExpiredAccountOnScheduledPostRule implements CampaignHealthRule
{
    private const FACT = 'scheduled.expired_accounts';

    public function prime(Collection $campaigns, CampaignHealthContext $context): void
    {
        $rows = DB::table('posts')
            ->join('post_targets', 'post_targets.post_id', '=', 'posts.id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->whereIn('posts.campaign_id', $campaigns->modelKeys())
            ->whereNull('posts.deleted_at')
            ->where('posts.status', PostStatus::Scheduled->value)
            ->where('social_accounts.connection_status', ConnectionStatus::TokenExpired->value)
            ->selectRaw('posts.campaign_id, count(distinct posts.id) as aggregate')
            ->groupBy('posts.campaign_id')
            ->pluck('aggregate', 'campaign_id');

        $context->put(self::FACT, array_map('intval', $rows->all()));
    }

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        $count = (int) $context->get(self::FACT, $campaign->id, 0);

        if ($count === 0) {
            return null;
        }

        return CampaignHealthFinding::red('expired_account', $count === 1
            ? '1 scheduled post goes to an account that needs reconnecting.'
            : "{$count} scheduled posts go to accounts that need reconnecting.");
    }
}
