<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Step 17.8 — how often a saved organization report is emailed to its recipients.
 */
enum ReportSchedule: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Every Monday',
            self::Monthly => 'First of the month',
        };
    }

    /**
     * The start of the current sending period in the org's clock: a report is sent at most once
     * per period, on the first daily run on or after this moment.
     */
    public function periodStart(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $now->startOfWeek(CarbonImmutable::MONDAY),
            self::Monthly => $now->startOfMonth(),
        };
    }
}
