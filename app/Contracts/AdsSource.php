<?php

namespace App\Contracts;

use App\Models\AdAccount;
use App\Support\Analytics\Ads\AdMetricsReading;

/**
 * Step GTM-5.1 — where paid-ads figures come from, modelled directly on {@see EngagementSource}.
 * Bound in `AppServiceProvider` from `config('ads.provider')`: `MetaAdsSource` for Meta, otherwise
 * `NullAdsSource`.
 *
 * **READ-ONLY BY CONSTRUCTION. Do not add a write method to this interface.**
 *
 * This is the whole design, not a preference. Alima reports on ad spend; it never creates, edits,
 * pauses or funds anything at the platform. Paid-ads *management* is an explicit non-goal of the
 * go-to-market plan (§1.1), reporting is roughly a tenth of the work and most of the value, and the
 * blast radius of a bug in code that can spend somebody's money is not comparable to one in code
 * that reads. There is deliberately nowhere in this module for a budget change to be plumbed
 * through: no `createCampaign`, no `updateBudget`, no `pause`. A step that wants those is a new
 * contract, a new decision and a conversation about liability — not an addition here.
 *
 * Returns null when there is nothing to report (no connection, no permission, nothing spent yet)
 * and **never throws**: a failed read is recorded on the ad account and shown as a gap.
 */
interface AdsSource
{
    /**
     * Logged with every reading, so a figure can be traced to where it came from.
     */
    public function key(): string;

    /**
     * The account's daily series back to `$sinceDays`, or null when it cannot be read at all.
     */
    public function accountMetrics(AdAccount $account, int $sinceDays): ?AdMetricsReading;
}
