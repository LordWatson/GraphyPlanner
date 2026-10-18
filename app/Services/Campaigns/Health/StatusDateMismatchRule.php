<?php

namespace App\Services\Campaigns\Health;

use App\Contracts\Campaigns\CampaignHealthRule;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Support\Campaigns\CampaignHealthContext;
use App\Support\Campaigns\CampaignHealthFinding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Status is intent, dates are fact (plan §1.3.3): we never flip a campaign's status for the team,
 * we tell them. Still Active (or Paused) after the end date is amber for the first week and red
 * once it's more than 7 days past.
 */
class StatusDateMismatchRule implements CampaignHealthRule
{
    public const RED_AFTER_DAYS = 7;

    public function prime(Collection $campaigns, CampaignHealthContext $context): void {}

    public function evaluate(Campaign $campaign, CampaignHealthContext $context): ?CampaignHealthFinding
    {
        if ($campaign->end_date === null || ! in_array($campaign->status, [CampaignStatus::Active, CampaignStatus::Paused], true)) {
            return null;
        }

        $end = CarbonImmutable::parse($campaign->end_date->toDateString(), $context->today->getTimezone());

        if (! $end->lessThan($context->today)) {
            return null;
        }

        $days = (int) $end->diffInDays($context->today);
        $ago = $days === 1 ? '1 day ago' : "{$days} days ago";
        $reason = "Ended {$ago}, still {$campaign->status->label()}.";

        return $days > self::RED_AFTER_DAYS
            ? CampaignHealthFinding::red('ended_still_open', $reason)
            : CampaignHealthFinding::amber('ended_still_open', $reason);
    }
}
