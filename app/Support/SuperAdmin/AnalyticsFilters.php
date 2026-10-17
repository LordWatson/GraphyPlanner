<?php

namespace App\Support\SuperAdmin;

use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Carbon\CarbonImmutable;

/**
 * Step 15.4/15.5 — the one filter shape shared by the platform analytics page (query string), the
 * per-org drill-down and saved reports (`super_admin_reports.filters`), so every surface resolves
 * a date range and metric selection the same way.
 *
 * The date range itself is an `App\Support\Analytics\AnalyticsRange` (Step 17.1, shared with
 * organization analytics). The platform has no single timezone, so it's always resolved in UTC.
 */
final readonly class AnalyticsFilters
{
    public const RANGES = AnalyticsRange::PRESETS;

    public const METRICS = ['organizations', 'users', 'clients', 'posts', 'revenue', 'ai'];

    public const DEFAULT_RANGE = AnalyticsRange::DEFAULT_PRESET;

    public string $range;

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    /**
     * @param  list<int>  $orgIds  empty = every organization
     * @param  list<string>  $metrics  empty = every metric section
     */
    public function __construct(
        public AnalyticsRange $period,
        public array $orgIds = [],
        public array $metrics = [],
    ) {
        $this->range = $period->preset;
        $this->from = $period->from;
        $this->to = $period->to;
    }

    /**
     * Build from user input or a stored report's `filters` JSON. Unknown values fall back to safe
     * defaults rather than throwing, so a report saved before a preset/metric was renamed still runs.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, ?CarbonImmutable $now = null): self
    {
        $orgIds = array_values(array_unique(array_map('intval', array_filter(
            (array) ($input['org_ids'] ?? []),
            fn ($id) => is_numeric($id) && (int) $id > 0,
        ))));

        $metrics = array_values(array_intersect(self::METRICS, (array) ($input['metrics'] ?? [])));

        return new self(AnalyticsRange::fromArray($input, 'UTC', $now), $orgIds, $metrics);
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
        return $this->period->granularity();
    }

    /**
     * The shared-core scope for a resolved list of organization ids.
     *
     * @param  list<int>  $orgIds
     */
    public function scope(array $orgIds): AnalyticsScope
    {
        return AnalyticsScope::organizations($orgIds);
    }

    /**
     * Copy scoped to one organization (the per-org drill-down).
     */
    public function forOrganization(int $orgId): self
    {
        return new self($this->period, [$orgId], $this->metrics);
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
            ...$this->period->toStoredArray(),
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
