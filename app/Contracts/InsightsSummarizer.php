<?php

namespace App\Contracts;

use App\Models\Client;

/**
 * Step B-8.2 — "plain-language takeaways": turn a brand's own insight numbers (reach by format, best
 * slot, follower growth, streak…) into at most three short, concrete sentences a non-marketer can
 * act on, e.g. "Your reels got 3× the reach of photos this month" or "Tuesday 8am is your best
 * slot." Its own contract (bound like {@see LocalIdeaProvider}) so it rides the DeepSeek text model
 * without touching the agency {@see AiProvider} doubles.
 *
 * Summary only — nothing is persisted here; the Action caches and meters. Never throws: a
 * disabled/failed provider returns an empty array, and the Insights page/digest simply omit the
 * takeaways rather than showing an error.
 */
interface InsightsSummarizer
{
    /**
     * @param  array<string, mixed>  $facts  compact, already-computed brand metrics (no raw rows)
     * @return list<string> up to three plain-language sentences (empty when none could be produced)
     */
    public function summarizeInsights(Client $brand, array $facts): array;
}
