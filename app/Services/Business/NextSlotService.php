<?php

namespace App\Services\Business;

use App\Support\Workspace\PostingGoal;
use Illuminate\Support\Carbon;

/**
 * Step B-7.2 — the "Next free slot" the quick post flow offers. Pure and timezone-aware (like
 * {@see PostingGoalGapService}) so it's unit-testable without the database: the caller passes the
 * brand's posting goal, its timezone, "now", the local dates that already have a post, and the
 * default posting hour.
 *
 * It picks the soonest day the brand aims to post on (its posting-goal days, or any day when no
 * goal is set) that has nothing scheduled, at the default hour — skipping today once that hour has
 * passed so the suggestion is never in the past.
 */
class NextSlotService
{
    private const SEARCH_DAYS = 21;

    public function __construct(private readonly PostingGoalGapService $gaps) {}

    /**
     * @param  list<string>  $scheduledLocalDates  `Y-m-d` dates (brand timezone) with a post already
     * @return array{date: string, time: string}
     */
    public function next(PostingGoal $goal, string $timezone, Carbon $now, array $scheduledLocalDates, int $defaultHour): array
    {
        $hour = max(0, min(23, $defaultHour));
        $time = sprintf('%02d:00', $hour);
        $goalDays = $this->gaps->effectiveDays($goal);
        $scheduled = array_fill_keys($scheduledLocalDates, true);

        $localNow = $now->clone()->setTimezone($timezone);
        $start = $localNow->clone()->startOfDay();

        for ($i = 0; $i < self::SEARCH_DAYS; $i++) {
            $day = $start->clone()->addDays($i);

            // Respect posting-goal days when the brand set any; otherwise every day is a candidate.
            if ($goalDays !== [] && ! in_array($day->dayOfWeekIso, $goalDays, true)) {
                continue;
            }

            // Today only counts if the default hour hasn't already passed.
            if ($i === 0 && $localNow->hour >= $hour) {
                continue;
            }

            $date = $day->toDateString();

            if (isset($scheduled[$date])) {
                continue;
            }

            return ['date' => $date, 'time' => $time];
        }

        // Everything in the window is taken (or blocked): fall back to tomorrow at the default hour.
        return ['date' => $start->clone()->addDay()->toDateString(), 'time' => $time];
    }
}
