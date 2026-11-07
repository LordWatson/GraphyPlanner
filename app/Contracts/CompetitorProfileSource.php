<?php

namespace App\Contracts;

use App\Models\TrackedProfile;
use App\Support\Analytics\Benchmarks\CompetitorProfileReading;

/**
 * Step GTM-4.1 — public figures for a profile we do **not** own, alongside `ProfileFeedSource`
 * (which reads a profile we do). Bound to `CompositeCompetitorProfileSource`.
 *
 * Three rules every implementation keeps:
 *
 *  - **Never throw to the page.** Same contract as `ProfileFeedSource`: a platform that blocks us,
 *    rate-limits us or has no legitimate public endpoint returns `null`, which the caller records
 *    as a gap.
 *  - **Never invent a figure.** A field the platform didn't report stays null all the way to the
 *    chart. A fabricated zero would make a competitor look worse than they are, which is the one
 *    thing a benchmark must not do.
 *  - **Only what the platform's own terms allow.** No scraping, no logged-out HTML parsing. Today
 *    that means Instagram through Meta's Business Discovery (asked through an IG Business account
 *    the brand owns, which is the endpoint Meta provides for exactly this) and nothing else — see
 *    `benchmarks.md`.
 */
interface CompetitorProfileSource
{
    /**
     * Stored nowhere yet, but logged with every reading so a figure can be traced to its source.
     */
    public function key(): string;

    /**
     * Null when this source cannot read this profile at all.
     */
    public function profile(TrackedProfile $profile): ?CompetitorProfileReading;
}
