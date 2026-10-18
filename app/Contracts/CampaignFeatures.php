<?php

namespace App\Contracts;

use App\Enums\CampaignFeature;
use App\Models\Organization;

/**
 * Campaigns enhancement C-9.4 — the one place that decides whether an organization has a campaign
 * feature. Routes enforce it through the `campaign.feature` middleware and pages receive it through
 * `can`, so no controller branches on it. Today's implementation reads settings; a plan-entitlement
 * implementation replaces it once I-11.3 / B-9 land.
 */
interface CampaignFeatures
{
    public function enabled(?Organization $organization, CampaignFeature $feature): bool;

    /**
     * @return array<string, bool> keyed by `CampaignFeature` value
     */
    public function all(?Organization $organization): array;
}
