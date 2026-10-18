<?php

namespace App\Contracts\Campaigns;

use App\Models\Campaign;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Illuminate\Database\Eloquent\Collection;

/**
 * Step C-0.4 — one campaign health rule. Each plan phase adds its own rule (tagged
 * `campaign.health-rules` in `CampaignServiceProvider`) without editing `CampaignHealthService`.
 *
 * `prime()` runs once per batch of campaigns (the hub lists dozens) and stores whatever facts the
 * rule needs in the context with grouped queries, so `evaluate()` never queries per campaign.
 */
interface CampaignHealthRule
{
    /**
     * @param  Collection<int, Campaign>  $campaigns
     */
    public function prime(Collection $campaigns, CampaignHealthContext $context): void;

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding;
}
