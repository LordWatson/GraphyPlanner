<?php

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;

/**
 * Step 17.1 — the one date-range shape every analytics surface resolves (the platform console in
 * UTC, an organization in its own `default_timezone`).
 *
 * Ranges are relative presets by default (`30d` = the 30 days ending today), so a saved report
 * stays current every time it's run; `custom` pins explicit `from`/`to` dates. Presets resolve
 * against "now" *in the range's timezone*, and `from`/`to` are kept in that timezone — they're only
 * converted to UTC at query time (`fromUtc()` / `toUtc()`), so "last 30 days" and every daily bucket
 * follow the viewer's clock rather than the server's.
 */
final readonly class AnalyticsRange
{
    public const PRESETS = ['7d', '30d', '90d', '12m', 'ytd', 'custom'];

    public const DEFAULT_PRESET = '30d';

    /**
     * Ranges up to this many days are charted per day; anything longer per month.
     */
    private const DAILY_SERIES_MAX_DAYS = 62;

    public function __construct(
        public string $preset,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone = 'UTC',
    ) {}

    /**
     * Build from user input or stored filters (`range`, `from`, `to`). Unknown or invalid values
     * fall back to the default preset rather than throwing, so a report saved before a preset was
     * renamed still runs.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, string $timezone = 'UTC', ?CarbonImmutable $now = null): self
    {
        $timezone = self::validTimezone($timezone);
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $preset = in_array($input['range'] ?? null, self::PRESETS, true) ? (string) $input['range'] : self::DEFAULT_PRESET;

        [$from, $to] = self::resolveDates($preset, $input['from'] ?? null, $input['to'] ?? null, $now, $timezone);

        if ($from === null || $to === null) {
            $preset = self::DEFAULT_PRESET;
            [$from, $to] = self::resolveDates($preset, null, null, $now, $timezone);
        }

        /** @var CarbonImmutable $from */
        /** @var CarbonImmutable $to */
        return new self($preset, $from, $to, $timezone);
    }

    private static function validTimezone(string $timezone): string
    {
        return in_array($timezone, timezone_identifiers_list(), true) || $timezone === 'UTC' ? $timezone : 'UTC';
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function resolveDates(string $preset, mixed $from, mixed $to, CarbonImmutable $now, string $timezone): array
    {
        $end = $now->endOfDay();

        return match ($preset) {
            '7d' => [$end->subDays(6)->startOfDay(), $end],
            '30d' => [$end->subDays(29)->startOfDay(), $end],
            '90d' => [$end->subDays(89)->startOfDay(), $end],
            '12m' => [$end->subMonths(11)->startOfMonth(), $end],
            'ytd' => [$end->startOfYear(), $end],
            default => self::customDates($from, $to, $timezone),
        };
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function customDates(mixed $from, mixed $to, string $timezone): array
    {
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return [null, null];
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $from, $timezone);
            $end = CarbonImmutable::createFromFormat('Y-m-d', $to, $timezone);
        } catch (\Throwable) {
            return [null, null];
        }

        if (! $start || ! $end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $start->greaterThan($end)) {
            return [null, null];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }

    /**
     * The range start as a UTC instant — what timestamp columns are compared against.
     */
    public function fromUtc(): CarbonImmutable
    {
        return $this->from->setTimezone('UTC');
    }

    /**
     * The range end as a UTC instant.
     */
    public function toUtc(): CarbonImmutable
    {
        return $this->to->setTimezone('UTC');
    }

    /**
     * The local calendar dates the range covers — what `date` columns (`issue_date`) are compared
     * against, since a calendar date has no timezone to convert.
     *
     * @return array{0: string, 1: string}
     */
    public function dateBounds(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /**
     * `[fromUtc, toUtc]` for `whereBetween()` on a timestamp column.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBounds(): array
    {
        return [$this->fromUtc(), $this->toUtc()];
    }

    public function granularity(): string
    {
        return $this->from->diffInDays($this->to) < self::DAILY_SERIES_MAX_DAYS ? 'day' : 'month';
    }

    /**
     * The persisted form. Custom ranges keep their explicit dates; relative presets don't, so they
     * re-resolve against "today" on every run.
     *
     * @return array{range: string, from: string|null, to: string|null}
     */
    public function toStoredArray(): array
    {
        return [
            'range' => $this->preset,
            'from' => $this->preset === 'custom' ? $this->from->toDateString() : null,
            'to' => $this->preset === 'custom' ? $this->to->toDateString() : null,
        ];
    }

    /**
     * The resolved form sent to the frontend (dates always filled in).
     *
     * @return array{range: string, from: string, to: string, granularity: string, timezone: string}
     */
    public function toArray(): array
    {
        return [
            'range' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'granularity' => $this->granularity(),
            'timezone' => $this->timezone,
        ];
    }
}
