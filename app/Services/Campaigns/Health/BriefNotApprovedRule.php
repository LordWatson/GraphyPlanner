<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\BriefStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignBrief;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Illuminate\Database\Eloquent\Collection;

/**
 * Step C-2.1 — amber: the campaign is live (Active) but its brief isn't signed off. A campaign
 * with no brief at all counts too: there's nothing on record that the client agreed to.
 */
class BriefNotApprovedRule implements CampaignHealthRule
{
    private const FACT = 'brief.status';

    public function prime(Collection $campaigns, CampaignHealthContext $context): void
    {
        $active = $campaigns->filter(fn (Campaign $campaign) => $campaign->status === CampaignStatus::Active)->modelKeys();

        $context->put(self::FACT, $active === [] ? [] : CampaignBrief::query()
            ->whereIn('campaign_id', $active)
            ->pluck('status', 'campaign_id')
            ->map(fn ($status) => $status instanceof BriefStatus ? $status->value : $status)
            ->all());
    }

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        if ($campaign->status !== CampaignStatus::Active) {
            return null;
        }

        $status = $context->get(self::FACT, $campaign->id);

        if ($status === BriefStatus::Approved->value) {
            return null;
        }

        return CampaignHealthFinding::amber('brief_not_approved', match ($status) {
            BriefStatus::Sent->value => 'Live, and the brief is still with the client.',
            BriefStatus::ChangesRequested->value => 'Live, and the client asked for changes to the brief.',
            null => 'Live without a brief on record.',
            default => 'Live, and the brief isn’t signed off.',
        });
    }
}
