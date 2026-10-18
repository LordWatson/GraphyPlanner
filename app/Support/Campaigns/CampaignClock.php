<?php

namespace App\Support\Campaigns;

use App\Models\Campaign;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Step C-0.4 — "today" for campaign maths, in the organization's timezone. Campaign dates are
 * calendar dates in the studio's own day, so a London studio's campaign doesn't "launch" at
 * midnight UTC.
 */
final class CampaignClock
{
    public static function today(Campaign|Organization|null $subject): CarbonImmutable
    {
        $organization = $subject instanceof Campaign ? $subject->organization : $subject;

        return CarbonImmutable::now(self::timezone($organization))->startOfDay();
    }

    public static function timezone(?Organization $organization): string
    {
        return $organization?->default_timezone ?: 'UTC';
    }
}
