<?php

namespace App\Support\Analytics;

use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Step 17.2 — the one filter shape for an organization's analytics: the page's query string, the
 * client drill-down, saved reports (`organization_reports.filters`) and scheduled emails all
 * resolve through it.
 *
 * - The range is resolved in the organization's own `default_timezone`.
 * - The org id always comes from the `Organization` model, never from input. Client and campaign
 *   ids are re-checked against that organization here as well as in validation, so a stored report
 *   whose client was later deleted (or a forged id) is dropped quietly instead of leaking or
 *   crashing.
 * - `metrics` is what was *asked for*; what a given viewer may see is decided at run time by
 *   `AnalyticsAccess::visibleMetrics()`, so saved reports stay runnable by everyone.
 */
final readonly class OrganizationAnalyticsFilters
{
    public const METRICS = ['clients', 'posts', 'approvals', 'team', 'revenue', 'ai'];

    /**
     * @param  list<int>  $clientIds  empty = every client
     * @param  list<int>  $campaignIds  empty = every campaign
     * @param  list<string>  $platforms  `Platform` values; empty = every platform
     * @param  list<string>  $metrics  empty = every section
     */
    public function __construct(
        public int $orgId,
        public AnalyticsRange $range,
        public array $clientIds = [],
        public array $campaignIds = [],
        public array $platforms = [],
        public array $metrics = [],
    ) {}

    /**
     * Build from user input or a stored report's `filters` JSON. Unknown values fall back to safe
     * defaults rather than throwing.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, Organization $organization, ?CarbonImmutable $now = null): self
    {
        $clientIds = self::ids($input['client_ids'] ?? []);
        $campaignIds = self::ids($input['campaign_ids'] ?? []);

        if ($clientIds !== []) {
            $clientIds = self::existing(Client::query()->where('org_id', $organization->id)->whereIn('id', $clientIds)->pluck('id')->all(), $clientIds);
        }

        if ($campaignIds !== []) {
            $campaignIds = self::existing(Campaign::query()->where('org_id', $organization->id)->whereIn('id', $campaignIds)->pluck('id')->all(), $campaignIds);
        }

        $platformValues = array_map(fn (Platform $platform) => $platform->value, Platform::cases());

        return new self(
            orgId: $organization->id,
            range: AnalyticsRange::fromArray($input, $organization->default_timezone ?: 'UTC', $now),
            clientIds: $clientIds,
            campaignIds: $campaignIds,
            platforms: array_values(array_intersect($platformValues, array_map('strval', (array) ($input['platforms'] ?? [])))),
            metrics: array_values(array_intersect(self::METRICS, (array) ($input['metrics'] ?? []))),
        );
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $input): array
    {
        return array_values(array_unique(array_map('intval', array_filter(
            (array) $input,
            fn ($id) => is_numeric($id) && (int) $id > 0,
        ))));
    }

    /**
     * The requested ids that exist, in the order they were requested.
     *
     * @param  array<int, mixed>  $found
     * @param  list<int>  $requested
     * @return list<int>
     */
    private static function existing(array $found, array $requested): array
    {
        $found = array_map('intval', $found);

        return array_values(array_filter($requested, fn (int $id) => in_array($id, $found, true)));
    }

    /**
     * Whether a section was asked for (an empty selection means "all").
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

    /**
     * The shared-core scope: this organization, narrowed by the client/campaign/platform filters.
     */
    public function scope(): AnalyticsScope
    {
        return new AnalyticsScope([$this->orgId], $this->clientIds, $this->campaignIds, $this->platforms);
    }

    /**
     * Copy scoped to one client (the client drill-down). Campaigns from other clients drop out.
     */
    public function forClient(int $clientId): self
    {
        $campaignIds = $this->campaignIds === []
            ? []
            : array_values(array_map('intval', Campaign::query()
                ->where('client_id', $clientId)
                ->whereIn('id', $this->campaignIds)
                ->pluck('id')
                ->all()));

        return new self($this->orgId, $this->range, [$clientId], $campaignIds, $this->platforms, $this->metrics);
    }

    /**
     * Copy with a different metric selection (e.g. one section pre-selected from the dashboard).
     *
     * @param  list<string>  $metrics
     */
    public function withMetrics(array $metrics): self
    {
        return new self($this->orgId, $this->range, $this->clientIds, $this->campaignIds, $this->platforms, array_values(array_intersect(self::METRICS, $metrics)));
    }

    /**
     * The client the run is scoped to, when exactly one is selected.
     */
    public function singleClientId(): ?int
    {
        return count($this->clientIds) === 1 ? $this->clientIds[0] : null;
    }

    /**
     * The persisted form (what a saved report stores). Presets stay relative; custom ranges stay
     * pinned. Never stores the org id — that's the report's own `org_id` column.
     *
     * @return array{range: string, from: string|null, to: string|null, client_ids: list<int>, campaign_ids: list<int>, platforms: list<string>, metrics: list<string>}
     */
    public function toStoredArray(): array
    {
        return [
            ...$this->range->toStoredArray(),
            'client_ids' => $this->clientIds,
            'campaign_ids' => $this->campaignIds,
            'platforms' => $this->platforms,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * The resolved form sent to the frontend (dates always filled in).
     *
     * @return array{range: string, from: string, to: string, granularity: string, timezone: string, client_ids: list<int>, campaign_ids: list<int>, platforms: list<string>, metrics: list<string>}
     */
    public function toArray(): array
    {
        return [
            ...$this->range->toArray(),
            'client_ids' => $this->clientIds,
            'campaign_ids' => $this->campaignIds,
            'platforms' => $this->platforms,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * The query string that reproduces this run (links, "Save as report", exports).
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'range' => $this->range->preset,
            'from' => $this->range->preset === 'custom' ? $this->range->from->toDateString() : null,
            'to' => $this->range->preset === 'custom' ? $this->range->to->toDateString() : null,
            'client_ids' => $this->clientIds,
            'campaign_ids' => $this->campaignIds,
            'platforms' => $this->platforms,
            'metrics' => $this->metrics,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
