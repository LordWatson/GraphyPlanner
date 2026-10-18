<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * Step I-2.3 — the date range a calendar request covers. The frontend asks for exactly the grid it
 * shows (the 6-week month grid containing its anchor); with no range, the server serves the grid
 * of the current month in the org's timezone. Spans are capped, so no request can pull history.
 *
 * Targets are matched on `scheduled_local_date` (indexed) widened by two days each side: the
 * "org timezone" rendering of an instant can land up to two calendar days from its account-local
 * date (UTC+14 vs UTC−12), and the client groups by whichever date the toggle shows.
 */
final readonly class CalendarWindow
{
    public const MAX_DAYS = 62;

    private const GRID_DAYS = 42;

    private const TIMEZONE_SLACK_DAYS = 2;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function resolve(?string $from, ?string $to, string $timezone): self
    {
        $start = $from !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $from) : null;
        $end = $to !== null ? CarbonImmutable::createFromFormat('!Y-m-d', $to) : null;

        if (! $start instanceof CarbonImmutable) {
            return self::monthGrid(CarbonImmutable::now($timezone));
        }

        if (! $end instanceof CarbonImmutable || $end->lessThan($start)) {
            $end = $start->addDays(self::GRID_DAYS - 1);
        }

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            $end = $start->addDays(self::MAX_DAYS);
        }

        return new self($start, $end);
    }

    /**
     * The Sunday-first 6-week grid that contains the given day's month — the same grid
     * `content-calendar.tsx` draws.
     */
    public static function monthGrid(CarbonImmutable $day): self
    {
        $monthStart = CarbonImmutable::create($day->year, $day->month, 1);
        $gridStart = $monthStart->subDays($monthStart->dayOfWeek);

        return new self($gridStart, $gridStart->addDays(self::GRID_DAYS - 1));
    }

    /**
     * The `post_targets.scheduled_local_date` range (inclusive) of targets that can render inside
     * the window — callers apply it with `whereBetween(self::TARGET_DATE_COLUMN, …)`.
     *
     * @return array{0: string, 1: string}
     */
    public function targetDateRange(): array
    {
        return [
            $this->from->subDays(self::TIMEZONE_SLACK_DAYS)->toDateString(),
            $this->to->addDays(self::TIMEZONE_SLACK_DAYS)->toDateString(),
        ];
    }

    public const TARGET_DATE_COLUMN = 'post_targets.scheduled_local_date';

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
