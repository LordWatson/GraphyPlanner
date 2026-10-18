<?php

namespace App\Support\Campaigns;

use Carbon\CarbonImmutable;

/**
 * Step C-4.4 — when a deliverable must be ready: its planned date minus the client's approval lead
 * time, counted in working days (Saturdays and Sundays skipped). A post planned for Monday with a
 * 3-day lead is due the Wednesday before.
 */
final class DeliverableDueDate
{
    public static function for(?CarbonImmutable $plannedFor, int $leadWorkingDays): ?CarbonImmutable
    {
        if ($plannedFor === null) {
            return null;
        }

        $due = $plannedFor->startOfDay();
        $remaining = max(0, $leadWorkingDays);

        while ($remaining > 0) {
            $due = $due->subDay();

            if (! $due->isWeekend()) {
                $remaining--;
            }
        }

        return $due;
    }
}
