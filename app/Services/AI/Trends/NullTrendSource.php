<?php

namespace App\Services\AI\Trends;

use App\Actions\AI\DiscoverTrendsAction;
use App\Contracts\TrendSource;
use App\Models\Client;
use App\Support\AI\AiTrends;

/**
 * Step M-7.5 — the no-signal fallback trend source: returns nothing, never queries. The default for
 * {@see DiscoverTrendsAction} when none is injected (e.g. in isolated unit tests).
 */
class NullTrendSource implements TrendSource
{
    public function discover(Client $client, array $platforms, int $limit): AiTrends
    {
        return new AiTrends;
    }

    public function label(): string
    {
        return 'none';
    }
}
