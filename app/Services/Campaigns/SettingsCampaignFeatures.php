<?php

namespace App\Services\Campaigns;

use App\Contracts\CampaignFeatures;
use App\Enums\CampaignFeature;
use App\Models\Organization;

/**
 * Campaigns enhancement C-9.4 — features from settings: the deployment default
 * (`config/campaigns.php`, all on) overridden per organization by `organizations.campaign_features`
 * (a map of feature → bool; missing keys fall back to the default).
 */
class SettingsCampaignFeatures implements CampaignFeatures
{
    public function enabled(?Organization $organization, CampaignFeature $feature): bool
    {
        $override = $organization?->campaign_features[$feature->value] ?? null;

        return is_bool($override)
            ? $override
            : (bool) config("campaigns.features.{$feature->value}", true);
    }

    public function all(?Organization $organization): array
    {
        $features = [];

        foreach (CampaignFeature::cases() as $feature) {
            $features[$feature->value] = $this->enabled($organization, $feature);
        }

        return $features;
    }
}
