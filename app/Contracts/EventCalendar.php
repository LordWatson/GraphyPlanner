<?php

namespace App\Contracts;

use App\Services\Events\StaticEventCalendar;
use App\Support\Events\EventOccurrence;
use Carbon\CarbonInterface;

/**
 * Step B-6.1 — the source of upcoming public holidays and awareness/seasonal days used to ground the
 * local/seasonal idea engine. Kept behind a contract (bound in a Service Provider) so the first,
 * static dataset ({@see StaticEventCalendar}) can later be swapped for a paid
 * API (open question §5.5) without touching the idea builder or its callers.
 *
 * Implementations never throw — a missing dataset or an unknown country yields an empty list — so an
 * idea generation never dead-ends on the calendar.
 */
interface EventCalendar
{
    /**
     * Events falling within `[from, from + days)`, soonest first, for the given country plus any
     * global events. A null country returns global events only.
     *
     * @param  string|null  $country  ISO-3166 alpha-2 code (e.g. `GB`, `US`), or null
     * @param  int  $days  the window length in days (clamped to a sane range by the implementation)
     * @return list<EventOccurrence>
     */
    public function upcoming(?string $country, CarbonInterface $from, int $days): array;
}
