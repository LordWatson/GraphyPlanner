<?php

namespace App\Services\Campaigns;

use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformRolloutGate;

/**
 * The platforms a campaign can plan work for: the client has an account on it (connected, or
 * connected before and needing a reconnect) and publishing to it is switched on. One rule for the
 * Plan tab's options, deliverable validation and the AI planner, so nobody plans posts for a
 * channel the client doesn't have.
 */
class CampaignPlatforms
{
    public function __construct(private readonly PlatformRolloutGate $rollout) {}

    /**
     * @return list<Platform> in `Platform::cases()` order
     */
    public function plannable(Campaign $campaign): array
    {
        $onAccounts = SocialAccount::query()->withoutOrgScope()
            ->where('client_id', $campaign->client_id)
            ->whereIn('connection_status', [ConnectionStatus::Connected, ConnectionStatus::TokenExpired])
            ->distinct()
            ->pluck('platform')
            ->map(fn ($platform) => $platform instanceof Platform ? $platform : Platform::from((string) $platform))
            ->all();

        return array_values(array_filter(
            Platform::cases(),
            fn (Platform $platform) => in_array($platform, $onAccounts, true) && $this->rollout->platformEnabled($platform),
        ));
    }

    /**
     * @return list<string>
     */
    public function plannableValues(Campaign $campaign): array
    {
        return array_map(fn (Platform $platform) => $platform->value, $this->plannable($campaign));
    }

    /**
     * What the AI may plan for: the campaign's intended platforms (all, when none are set), limited
     * to the plannable ones.
     *
     * @return list<string>
     */
    public function forAi(Campaign $campaign): array
    {
        $plannable = $this->plannableValues($campaign);

        return $campaign->platforms
            ? array_values(array_intersect($campaign->platforms, $plannable))
            : $plannable;
    }
}
