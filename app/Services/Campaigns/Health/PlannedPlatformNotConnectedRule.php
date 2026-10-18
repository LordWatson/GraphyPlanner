<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\SocialAccount;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Illuminate\Database\Eloquent\Collection;

/**
 * Step C-4.5 — amber: the plan has deliverables for a platform the client has no connected
 * account on, so those posts can't go out (one of readiness's blockers, raised into health).
 */
class PlannedPlatformNotConnectedRule implements CampaignHealthRule
{
    private const FACT = 'platforms.missing';

    public function prime(Collection $campaigns, CampaignHealthContext $context): void
    {
        $planned = CampaignDeliverable::query()
            ->whereIn('campaign_id', $campaigns->modelKeys())
            ->distinct()
            ->get(['campaign_id', 'platform'])
            ->groupBy('campaign_id');

        if ($planned->isEmpty()) {
            $context->put(self::FACT, []);

            return;
        }

        $connected = SocialAccount::query()->withoutOrgScope()
            ->whereIn('client_id', $campaigns->pluck('client_id')->unique()->all())
            ->where('connection_status', ConnectionStatus::Connected)
            ->get(['client_id', 'platform'])
            ->groupBy('client_id')
            ->map(fn ($accounts) => $accounts->map(fn (SocialAccount $account) => $account->platform)->all());

        $missing = [];

        foreach ($campaigns as $campaign) {
            $have = $connected->get($campaign->client_id, []);
            $labels = $planned->get($campaign->id, collect())
                ->map(fn (CampaignDeliverable $deliverable) => $deliverable->platform)
                ->reject(fn (Platform $platform) => in_array($platform, $have, true))
                ->map(fn (Platform $platform) => $platform->label())
                ->unique()
                ->values()
                ->all();

            if ($labels !== []) {
                $missing[$campaign->id] = $labels;
            }
        }

        $context->put(self::FACT, $missing);
    }

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        /** @var list<string> $labels */
        $labels = $context->get(self::FACT, $campaign->id, []);

        if ($labels === []) {
            return null;
        }

        $list = count($labels) === 1 ? $labels[0] : implode(', ', array_slice($labels, 0, -1)).' or '.end($labels);

        return CampaignHealthFinding::amber('platform_not_connected', "Planned for {$list}, but no account is connected.");
    }
}
