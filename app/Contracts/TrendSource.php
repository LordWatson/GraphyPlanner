<?php

namespace App\Contracts;

use App\Models\Client;
use App\Support\AI\AiTrends;

/**
 * Step M-7.5 (dev plan I-9.5) — "honest trends". A source of trend signal for a client, behind a
 * contract so the real-signal sources (the client's own top posts today; Google Trends / TikTok
 * Creative Center later) are swappable and testable. Each returned trend is tagged with this
 * source's {@see label()} so the UI can be honest about where it came from. DeepSeek's model
 * hypotheses are a separate, clearly-labelled path (not a TrendSource) since they aren't live data.
 */
interface TrendSource
{
    /**
     * @param  array<int, string>  $platforms
     */
    public function discover(Client $client, array $platforms, int $limit): AiTrends;

    /**
     * A short human label shown on each trend from this source (e.g. "Your top posts").
     */
    public function label(): string;
}
