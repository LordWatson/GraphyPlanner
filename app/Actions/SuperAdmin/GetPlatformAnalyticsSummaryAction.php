<?php

namespace App\Actions\SuperAdmin;

use App\Enums\ClientStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Services\Analytics\AiUsageMetrics;
use App\Services\Analytics\PostActivityMetrics;
use App\Services\Analytics\RevenueMetrics;
use App\Support\Analytics\AnalyticsScope;
use App\Support\Analytics\SeriesBuckets;
use App\Support\SuperAdmin\AnalyticsFilters;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.4 — the platform-wide reporting layer: every figure is a plain aggregate over tables
 * the rest of the app already writes (organizations, users, clients, posts + their activity log,
 * invoices, AI usage logs). No new domain model and no stored aggregates.
 *
 * Shared by the analytics page, the super admin overview and saved reports (`RunReportAction`),
 * so every surface agrees. (The per-org drill-down uses organization analytics since Step 17.9.)
 *
 * - The Preview Studio fixture org is excluded (demo data, not a customer) unless it's
 *   explicitly selected.
 * - Money is never summed across currencies: each money figure is a per-currency list, and the
 *   chart series uses the range's `primary_currency` (the one with the most invoiced).
 * - Invoices are what organizations bill *their* clients through the app — platform GMV, not
 *   Graphy subscription revenue (no subscription billing exists yet).
 * - Engagement (Phase 12 `PostTargetMetrics`) isn't built yet, so `engagement` is always null.
 * - Step 17.1: post/revenue/AI queries, range resolution and bucketing live in the shared
 *   analytics core (`App\Services\Analytics`, `App\Support\Analytics`), which organization
 *   analytics uses too; this action only adds the platform-only sections.
 */
class GetPlatformAnalyticsSummaryAction
{
    private const TOP_ORGANIZATIONS = 10;

    public function __construct(
        private readonly PostActivityMetrics $postMetrics,
        private readonly RevenueMetrics $revenueMetrics,
        private readonly AiUsageMetrics $aiMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(AnalyticsFilters $filters): array
    {
        $from = $filters->from;
        $to = $filters->to;
        $orgIds = $this->scopedOrgIds($filters);
        $scope = $filters->scope($orgIds);

        $summary = [
            'filters' => $filters->toArray(),
            'metrics' => $filters->selectedMetrics(),
            'organizations' => $filters->includes('organizations') ? $this->organizations($orgIds, $from, $to) : null,
            'users' => $filters->includes('users') ? $this->users($orgIds, $from, $to) : null,
            'clients' => $filters->includes('clients') ? $this->clients($orgIds, $from, $to) : null,
            'posts' => $filters->includes('posts') ? $this->posts($scope, $filters) : null,
            'revenue' => $filters->includes('revenue') ? $this->revenueMetrics->summary($scope, $filters->period) : null,
            'ai' => $filters->includes('ai') ? $this->aiMetrics->summary($scope, $filters->period) : null,
            'engagement' => null,
        ];

        $primaryCurrency = $this->revenueMetrics->primaryCurrency($scope, $filters->period);

        $summary['primary_currency'] = $primaryCurrency;
        $summary['series'] = $this->series($filters, $scope, $orgIds, $primaryCurrency);
        $summary['top_organizations'] = $this->topOrganizations($orgIds, $from, $to, $primaryCurrency);

        Log::info('Platform analytics summary built', [
            'range' => $filters->range,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'org_ids' => $filters->orgIds,
            'metrics' => $filters->metrics,
        ]);

        return $summary;
    }

    /**
     * Every in-scope organization id: the requested ids when given (so the preview fixture's own
     * drill-down still works), otherwise every customer org.
     *
     * @return list<int>
     */
    private function scopedOrgIds(AnalyticsFilters $filters): array
    {
        return array_values(Organization::query()
            ->when(
                $filters->orgIds === [],
                fn (Builder $query) => $query->excludingPreview(),
                fn (Builder $query) => $query->whereIn('id', $filters->orgIds),
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{total: int, active: int, suspended: int, new: int}
     */
    private function organizations(array $orgIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = fn () => Organization::query()->whereIn('id', $orgIds)->where('created_at', '<=', $to);

        return [
            'total' => $base()->count(),
            'active' => $base()->active()->count(),
            'suspended' => $base()->suspended()->count(),
            'new' => $base()->where('created_at', '>=', $from)->count(),
        ];
    }

    /**
     * Organization members only — super admins (org-less platform staff) are never counted.
     *
     * @param  list<int>  $orgIds
     * @return array{total: int, new: int, by_role: list<array{role: string, label: string, count: int}>}
     */
    private function users(array $orgIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = fn () => User::query()
            ->whereIn('org_id', $orgIds)
            ->where('created_at', '<=', $to);

        $byRole = $base()
            ->toBase()
            ->selectRaw('role, COUNT(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        $roles = [];

        foreach (Role::cases() as $role) {
            if ($role === Role::SuperAdmin) {
                continue;
            }

            $roles[] = ['role' => $role->value, 'label' => $role->label(), 'count' => (int) ($byRole[$role->value] ?? 0)];
        }

        return [
            'total' => $base()->count(),
            'new' => $base()->where('created_at', '>=', $from)->count(),
            'by_role' => $roles,
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{total: int, active: int, new: int}
     */
    private function clients(array $orgIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = fn () => Client::query()->whereIn('org_id', $orgIds)->where('created_at', '<=', $to);

        return [
            'total' => $base()->count(),
            'active' => $base()->where('status', ClientStatus::Active)->count(),
            'new' => $base()->where('created_at', '>=', $from)->count(),
        ];
    }

    /**
     * "Published"/"failed" in a window are counted from the post activity log (the moment each
     * transition happened, as the dashboard's publish reliability chart does), not `updated_at`.
     *
     * @return array{total: int, published_all_time: int, published: int, published_last_30_days: int, failed: int, success_rate: float|null, scheduled: int}
     */
    private function posts(AnalyticsScope $scope, AnalyticsFilters $filters): array
    {
        $outcomes = $this->postMetrics->publishOutcomes($scope, $filters->period);

        return [
            'total' => $scope->posts()->where('created_at', '<=', $filters->to)->count(),
            'published_all_time' => $scope->posts()->where('status', PostStatus::Published)->count(),
            'published' => $outcomes['published'],
            'published_last_30_days' => $this->postMetrics->transitions($scope, PostStatus::Published)
                ->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
                ->count(),
            'failed' => $outcomes['failed'],
            'success_rate' => $outcomes['success_rate'],
            'scheduled' => $scope->posts()->where('status', PostStatus::Scheduled)->count(),
        ];
    }

    /**
     * One bucket per day (ranges up to ~2 months) or per month, each carrying signups, published,
     * failed, and invoiced in the primary currency.
     *
     * @param  list<int>  $orgIds
     * @return list<array{key: string, label: string, signups: int, published: int, failed: int, invoiced: float}>
     */
    private function series(AnalyticsFilters $filters, AnalyticsScope $scope, array $orgIds, string $currency): array
    {
        $buckets = new SeriesBuckets($filters->period);

        $signups = $buckets->countByBucket(
            Organization::query()->whereIn('id', $orgIds)->whereBetween('created_at', [$filters->from, $filters->to])->pluck('created_at'),
        );

        $logs = $this->postMetrics->transitionTimes($scope, $filters->period, [PostStatus::Published, PostStatus::Failed]);

        $published = $buckets->countByBucket($logs->where('to_status', PostStatus::Published)->pluck('created_at'));
        $failed = $buckets->countByBucket($logs->where('to_status', PostStatus::Failed)->pluck('created_at'));
        $invoiced = $buckets->sumByBucket($this->revenueMetrics->invoicedPairs($scope, $filters->period, $currency), calendarDates: true);

        return array_map(fn (array $bucket) => [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'signups' => $signups[$bucket['key']] ?? 0,
            'published' => $published[$bucket['key']] ?? 0,
            'failed' => $failed[$bucket['key']] ?? 0,
            'invoiced' => (float) ($invoiced[$bucket['key']] ?? 0.0),
        ], $buckets->buckets());
    }

    /**
     * The most active organizations in the range, by posts published.
     *
     * @param  list<int>  $orgIds
     * @return list<array{id: int, name: string, slug: string, suspended: bool, published: int, clients: int, users: int, invoiced: float}>
     */
    private function topOrganizations(array $orgIds, CarbonInterface $from, CarbonInterface $to, string $currency): array
    {
        if ($orgIds === []) {
            return [];
        }

        $published = PostActivityLog::query()
            ->join('posts', 'posts.id', '=', 'post_activity_logs.post_id')
            ->whereIn('posts.org_id', $orgIds)
            ->where('post_activity_logs.to_status', PostStatus::Published)
            ->whereBetween('post_activity_logs.created_at', [$from, $to])
            ->toBase()
            ->selectRaw('posts.org_id as org_id, COUNT(*) as aggregate')
            ->groupBy('posts.org_id')
            ->pluck('aggregate', 'org_id');

        $invoiced = $this->revenueMetrics->billed(AnalyticsScope::organizations($orgIds))
            ->where('currency', $currency)
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('org_id, SUM(amount) as aggregate')
            ->groupBy('org_id')
            ->pluck('aggregate', 'org_id');

        return array_values(Organization::query()
            ->whereIn('id', $orgIds)
            ->withCount(['clients', 'users'])
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'suspended' => $organization->isSuspended(),
                'published' => (int) ($published[$organization->id] ?? 0),
                'clients' => (int) $organization->getAttribute('clients_count'),
                'users' => (int) $organization->getAttribute('users_count'),
                'invoiced' => round((float) ($invoiced[$organization->id] ?? 0), 2),
            ])
            ->sortBy([['published', 'desc'], ['invoiced', 'desc'], ['name', 'asc']])
            ->take(self::TOP_ORGANIZATIONS)
            ->all());
    }
}
