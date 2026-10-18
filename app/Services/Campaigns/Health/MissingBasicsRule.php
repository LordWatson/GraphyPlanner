<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Illuminate\Database\Eloquent\Collection;

/**
 * Amber: nobody is accountable for the campaign, or it has no dates to plan against.
 */
class MissingBasicsRule implements CampaignHealthRule
{
    public function prime(Collection $campaigns, CampaignHealthContext $context): void {}

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        if ($campaign->owner_id === null && $campaign->start_date === null) {
            return CampaignHealthFinding::amber('no_owner_or_dates', 'No owner and no dates yet.');
        }

        if ($campaign->owner_id === null) {
            return CampaignHealthFinding::amber('no_owner', 'No owner yet.');
        }

        if ($campaign->start_date === null) {
            return CampaignHealthFinding::amber('no_dates', 'No dates yet.');
        }

        return null;
    }
}
