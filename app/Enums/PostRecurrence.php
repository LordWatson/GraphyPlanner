<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Step M-5.8 — how often a post repeats. A recurring post drops a fresh **draft** of the next
 * occurrence (never a scheduled post) once the current one has published, so a human always
 * reviews and sends it.
 */
enum PostRecurrence: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
        };
    }

    /**
     * Advance a local date by one interval, without overflowing a short month — monthly on the
     * 31st lands on the last day of the next month (e.g. 31 Jan → 28/29 Feb) rather than skipping
     * into March. Operates on the date only; the caller keeps the local wall-clock time and
     * re-derives UTC in the account's timezone (so a DST change keeps the local time).
     */
    public function advance(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Weekly => $date->copy()->addWeek(),
            self::Monthly => $date->copy()->addMonthNoOverflow(),
        };
    }
}
