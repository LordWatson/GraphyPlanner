<?php

namespace App\Services\Analytics\Ads;

use App\Contracts\AdsSource;
use App\Models\AdAccount;
use App\Support\Analytics\Ads\AdMetricsReading;

/**
 * Step GTM-5.1 — the default: no ads provider configured, so no figures. Every connected account
 * simply records a gap, which is what an agency should see until `ADS_PROVIDER` is set and the Meta
 * app has `ads_read`.
 */
class NullAdsSource implements AdsSource
{
    public function key(): string
    {
        return 'null';
    }

    public function accountMetrics(AdAccount $account, int $sinceDays): ?AdMetricsReading
    {
        return null;
    }
}
