<?php

namespace App\Services\Business;

use App\Support\Workspace\PostingGoal;
use Illuminate\Support\Carbon;

/**
 * Step B-4.5 — works out which upcoming days a brand ought to post on but hasn't, given its posting
 * goal. Pure and timezone-aware so it can be unit-tested across week boundaries and DST without
 * touching the database: the caller passes the goal, the brand's timezone, "now", the window length
 * and the set of local dates (`Y-m-d`, already in the brand's timezone) that have a scheduled post.
 *
 * Feeds the Today dashboard's "Gaps" (B-4.3), the calendar's ghost slots and the AI weekly planner
 * (B-6.3).
 */
class PostingGoalGapService
{
    /**
     * The days the brand aims to post on, as ISO-8601 weekday numbers (1 = Mon … 7 = Sun), that have
     * nothing scheduled within the window.
     *
     * @param  list<string>  $scheduledLocalDates  `Y-m-d` dates (brand timezone) that already have a post
     * @return array{days: list<array{date: string, weekday: int, label: string}>, count: int, goalDays: list<int>}
     */
    public function gaps(PostingGoal $goal, string $timezone, Carbon $now, int $days, array $scheduledLocalDates): array
    {
        $goalDays = $this->effectiveDays($goal);

        if ($goalDays === []) {
            return ['days' => [], 'count' => 0, 'goalDays' => []];
        }

        $scheduled = array_fill_keys($scheduledLocalDates, true);
        // Start from "today" in the brand's timezone, not the raw instant, so a post later today
        // still counts today as covered.
        $cursor = $now->clone()->setTimezone($timezone)->startOfDay();
        $gaps = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $cursor->clone()->addDays($i);
            $iso = $day->dayOfWeekIso;

            if (! in_array($iso, $goalDays, true)) {
                continue;
            }

            $date = $day->toDateString();

            if (isset($scheduled[$date])) {
                continue;
            }

            $gaps[] = [
                'date' => $date,
                'weekday' => $iso,
                'label' => $day->isoFormat('ddd D MMM'),
            ];
        }

        return ['days' => $gaps, 'count' => count($gaps), 'goalDays' => $goalDays];
    }

    /**
     * The weekday numbers the brand targets: its explicit `preferredDays` when set, otherwise a
     * deterministic even spread of `postsPerWeek` across the week (3/week → Mon, Wed, Fri), so a
     * brand that gave only a weekly count still gets day-level gaps to fill.
     *
     * @return list<int>
     */
    public function effectiveDays(PostingGoal $goal): array
    {
        if ($goal->preferredDays !== []) {
            return $goal->preferredDays;
        }

        $perWeek = $goal->postsPerWeek;

        if ($perWeek === null || $perWeek <= 0) {
            return [];
        }

        if ($perWeek >= 7) {
            return [1, 2, 3, 4, 5, 6, 7];
        }

        $days = [];

        // Spread n days evenly across the 7-day week, biased to weekdays first.
        for ($i = 0; $i < $perWeek; $i++) {
            $days[] = (int) floor($i * 7 / $perWeek) + 1;
        }

        return array_values(array_unique($days));
    }
}
