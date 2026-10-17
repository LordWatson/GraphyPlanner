<?php

namespace App\Support\SuperAdmin;

use Carbon\CarbonImmutable;

/**
 * Step 15.4/15.5 — the one filter shape shared by the platform analytics page (query string), the
 * per-org drill-down and saved reports (`super_admin_reports.filters`), so every surface resolves
 * a date range and metric selection the same way.
 *
 * Ranges are relative presets by default (`30d` = the 30 days ending today), so a saved report
 * stays current every time it's run; `custom` pins explicit `from`/`to` dates. The platform has
 * no single timezone, so dates are resolved in UTC.
 */
final readonly class AnalyticsFilters
{
    public const RANGES = ['7d', '30d', '90d', '12m', 'ytd', 'custom'];

    public const METRICS = ['organizations', 'users', 'clients', 'posts', 'revenue', 'ai'];

    public const DEFAULT_RANGE = '30d';

    /**
     * Ranges up to this many days are charted per day; anything longer per month.
     */
    private const DAILY_SERIES_MAX_DAYS = 62;

    /**
     * @param  list<int>  $orgIds  empty = every organization
     * @param  list<string>  $metrics  empty = every metric section
     */
    public function __construct(
        public string $range,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $orgIds = [],
        public array $metrics = [],
    ) {}

    /**
     * Build from user input or a stored report's `filters` JSON. Unknown values fall back to safe
     * defaults rather than throwing, so a report saved before a preset/metric was renamed still runs.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->setTimezone('UTC');
        $range = in_array($input['range'] ?? null, self::RANGES, true) ? $input['range'] : self::DEFAULT_RANGE;

        [$from, $to] = self::resolveDates($range, $input['from'] ?? null, $input['to'] ?? null, $now);

        if ($from === null || $to === null) {
            $range = self::DEFAULT_RANGE;
            [$from, $to] = self::resolveDates($range, null, null, $now);
        }

        $orgIds = array_values(array_unique(array_map('intval', array_filter(
            (array) ($input['org_ids'] ?? []),
            fn ($id) => is_numeric($id) && (int) $id > 0,
        ))));

        $metrics = array_values(array_intersect(self::METRICS, (array) ($input['metrics'] ?? [])));

        /** @var CarbonImmutable $from */
        /** @var CarbonImmutable $to */
        return new self($range, $from, $to, $orgIds, $metrics);
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function resolveDates(string $range, mixed $from, mixed $to, CarbonImmutable $now): array
    {
        $end = $now->endOfDay();

        return match ($range) {
            '7d' => [$end->subDays(6)->startOfDay(), $end],
            '30d' => [$end->subDays(29)->startOfDay(), $end],
            '90d' => [$end->subDays(89)->startOfDay(), $end],
            '12m' => [$end->subMonths(11)->startOfMonth(), $end],
            'ytd' => [$end->startOfYear(), $end],
            default => self::customDates($from, $to),
        };
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function customDates(mixed $from, mixed $to): array
    {
        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return [null, null];
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $from, 'UTC');
            $end = CarbonImmutable::createFromFormat('Y-m-d', $to, 'UTC');
        } catch (\Throwable) {
            return [null, null];
        }

        if (! $start || ! $end || $start->greaterThan($end)) {
            return [null, null];
        }

        return [$start->startOfDay(), $end->endOfDay()];
    }

    /**
     * Whether a metric section is part of this run (an empty selection means "all").
     */
    public function includes(string $metric): bool
    {
        return $this->metrics === [] || in_array($metric, $this->metrics, true);
    }

    /**
     * @return list<string>
     */
    public function selectedMetrics(): array
    {
        return $this->metrics === [] ? self::METRICS : $this->metrics;
    }

    public function granularity(): string
    {
        return $this->from->diffInDays($this->to) < self::DAILY_SERIES_MAX_DAYS ? 'day' : 'month';
    }

    /**
     * Copy scoped to one organization (the per-org drill-down).
     */
    public function forOrganization(int $orgId): self
    {
        return new self($this->range, $this->from, $this->to, [$orgId], $this->metrics);
    }

    /**
     * The persisted form (what a saved report stores). Custom ranges keep their explicit dates;
     * relative presets don't, so they re-resolve against "today" on every run.
     *
     * @return array{range: string, from: string|null, to: string|null, org_ids: list<int>, metrics: list<string>}
     */
    public function toStoredArray(): array
    {
        return [
            'range' => $this->range,
            'from' => $this->range === 'custom' ? $this->from->toDateString() : null,
            'to' => $this->range === 'custom' ? $this->to->toDateString() : null,
            'org_ids' => $this->orgIds,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * The resolved form sent to the frontend (dates always filled in).
     *
     * @return array{range: string, from: string, to: string, org_ids: list<int>, metrics: list<string>, granularity: string}
     */
    public function toArray(): array
    {
        return [
            'range' => $this->range,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'org_ids' => $this->orgIds,
            'metrics' => $this->metrics,
            'granularity' => $this->granularity(),
        ];
    }
}
