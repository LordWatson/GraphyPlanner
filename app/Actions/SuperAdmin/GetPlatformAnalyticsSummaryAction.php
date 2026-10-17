<?php

namespace App\Actions\SuperAdmin;

use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\AiUsageLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Support\SuperAdmin\AnalyticsFilters;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.4 — the platform-wide reporting layer: every figure is a plain aggregate over tables
 * the rest of the app already writes (organizations, users, clients, posts + their activity log,
 * invoices, AI usage logs). No new domain model and no stored aggregates.
 *
 * Shared by the analytics page, the per-org drill-down (`AnalyticsFilters::forOrganization()`),
 * the super admin overview, and saved reports (`RunReportAction`), so every surface agrees.
 *
 * - The Preview Studio fixture org is excluded (demo data, not a customer) unless it's
 *   explicitly selected.
 * - Money is never summed across currencies: each money figure is a per-currency list, and the
 *   chart series uses the range's `primary_currency` (the one with the most invoiced).
 * - Invoices are what organizations bill *their* clients through the app — platform GMV, not
 *   Graphy subscription revenue (no subscription billing exists yet).
 * - Date bucketing happens in PHP, matching `GetPublishReliabilityStatsAction`, so it behaves the
 *   same on Postgres (production) and SQLite (tests).
 * - Engagement (Phase 12 `PostTargetMetrics`) isn't built yet, so `engagement` is always null.
 */
class GetPlatformAnalyticsSummaryAction
{
    private const TOP_ORGANIZATIONS = 10;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(AnalyticsFilters $filters): array
    {
        $from = $filters->from;
        $to = $filters->to;
        $orgIds = $this->scopedOrgIds($filters);

        $summary = [
            'filters' => $filters->toArray(),
            'metrics' => $filters->selectedMetrics(),
            'organizations' => $filters->includes('organizations') ? $this->organizations($orgIds, $from, $to) : null,
            'users' => $filters->includes('users') ? $this->users($orgIds, $from, $to) : null,
            'clients' => $filters->includes('clients') ? $this->clients($orgIds, $from, $to) : null,
            'posts' => $filters->includes('posts') ? $this->posts($orgIds, $from, $to) : null,
            'revenue' => $filters->includes('revenue') ? $this->revenue($orgIds, $from, $to) : null,
            'ai' => $filters->includes('ai') ? $this->ai($orgIds, $from, $to) : null,
            'engagement' => null,
        ];

        $primaryCurrency = $this->primaryCurrency($orgIds, $from, $to);

        $summary['primary_currency'] = $primaryCurrency;
        $summary['series'] = $this->series($filters, $orgIds, $primaryCurrency);
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
     * @param  list<int>  $orgIds
     * @return array{total: int, published_all_time: int, published: int, published_last_30_days: int, failed: int, success_rate: float|null, scheduled: int}
     */
    private function posts(array $orgIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $published = $this->transitions($orgIds, PostStatus::Published)->whereBetween('created_at', [$from, $to])->count();
        $failed = $this->transitions($orgIds, PostStatus::Failed)->whereBetween('created_at', [$from, $to])->count();

        return [
            'total' => Post::query()->whereIn('org_id', $orgIds)->where('created_at', '<=', $to)->count(),
            'published_all_time' => Post::query()->whereIn('org_id', $orgIds)->where('status', PostStatus::Published)->count(),
            'published' => $published,
            'published_last_30_days' => $this->transitions($orgIds, PostStatus::Published)
                ->where('created_at', '>=', CarbonImmutable::now()->subDays(30))
                ->count(),
            'failed' => $failed,
            'success_rate' => $published + $failed > 0 ? round($published / ($published + $failed) * 100, 1) : null,
            'scheduled' => Post::query()->whereIn('org_id', $orgIds)->where('status', PostStatus::Scheduled)->count(),
        ];
    }

    /**
     * @param  list<int>  $orgIds
     * @return Builder<PostActivityLog>
     */
    private function transitions(array $orgIds, PostStatus $status): Builder
    {
        return PostActivityLog::query()
            ->where('to_status', $status)
            ->whereIn('post_id', Post::query()->whereIn('org_id', $orgIds)->select('id'));
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{invoiced: list<array{currency: string, total: float}>, collected: list<array{currency: string, total: float}>, outstanding: list<array{currency: string, total: float}>, monthly_run_rate: list<array{currency: string, total: float}>, invoices_issued: int, invoices_paid: int}
     */
    private function revenue(array $orgIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $billed = fn () => Invoice::query()
            ->whereIn('org_id', $orgIds)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid]);

        $issued = $billed()->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()]);
        $paid = Invoice::query()
            ->whereIn('org_id', $orgIds)
            ->where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$from, $to]);

        // An MRR-style figure: what was billed in the 30 days up to the end of the range.
        $runRate = $billed()->whereBetween('issue_date', [
            CarbonImmutable::parse($to)->subDays(29)->toDateString(),
            $to->toDateString(),
        ]);

        return [
            'invoiced' => $this->totalsByCurrency(clone $issued),
            'collected' => $this->totalsByCurrency(clone $paid),
            'outstanding' => $this->totalsByCurrency(
                Invoice::query()->whereIn('org_id', $orgIds)->where('status', InvoiceStatus::Sent)
            ),
            'monthly_run_rate' => $this->totalsByCurrency($runRate),
            'invoices_issued' => $issued->count(),
            'invoices_paid' => $paid->count(),
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return list<array{currency: string, total: float}>
     */
    private function totalsByCurrency(Builder $query): array
    {
        return array_values($query
            ->toBase()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->get()
            ->map(fn (object $row) => ['currency' => (string) $row->currency, 'total' => round((float) $row->total, 2)])
            ->sortBy([['total', 'desc'], ['currency', 'asc']])
            ->values()
            ->all());
    }

    /**
     * @param  list<int>  $orgIds
     * @return array{generated: int, failed: int, cost_estimate: float}
     */
    private function ai(array $orgIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = fn () => AiUsageLog::query()->whereIn('org_id', $orgIds)->whereBetween('created_at', [$from, $to]);

        return [
            'generated' => $base()->where('succeeded', true)->count(),
            'failed' => $base()->where('succeeded', false)->count(),
            'cost_estimate' => round((float) $base()->sum('cost_estimate'), 2),
        ];
    }

    /**
     * The currency with the most invoiced in the range — the one the revenue series is drawn in.
     *
     * @param  list<int>  $orgIds
     */
    private function primaryCurrency(array $orgIds, CarbonInterface $from, CarbonInterface $to): string
    {
        $top = Invoice::query()
            ->whereIn('org_id', $orgIds)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid])
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->orderByDesc('total')
            ->orderBy('currency')
            ->value('currency');

        return is_string($top) ? $top : 'USD';
    }

    /**
     * One bucket per day (ranges up to ~2 months) or per month, each carrying signups, published,
     * failed, and invoiced in the primary currency.
     *
     * @param  list<int>  $orgIds
     * @return list<array{key: string, label: string, signups: int, published: int, failed: int, invoiced: float}>
     */
    private function series(AnalyticsFilters $filters, array $orgIds, string $currency): array
    {
        $from = $filters->from;
        $to = $filters->to;
        $daily = $filters->granularity() === 'day';
        $format = $daily ? 'Y-m-d' : 'Y-m';

        $signups = $this->countByBucket(
            Organization::query()->whereIn('id', $orgIds)->whereBetween('created_at', [$from, $to])->pluck('created_at'),
            $format,
        );

        $logs = PostActivityLog::query()
            ->whereIn('to_status', [PostStatus::Published, PostStatus::Failed])
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('post_id', Post::query()->whereIn('org_id', $orgIds)->select('id'))
            ->get(['to_status', 'created_at']);

        $published = $this->countByBucket($logs->where('to_status', PostStatus::Published)->pluck('created_at'), $format);
        $failed = $this->countByBucket($logs->where('to_status', PostStatus::Failed)->pluck('created_at'), $format);

        $invoiced = Invoice::query()
            ->whereIn('org_id', $orgIds)
            ->where('currency', $currency)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid])
            ->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->get(['amount', 'issue_date'])
            ->groupBy(fn (Invoice $invoice) => $invoice->issue_date->format($format))
            ->map(fn (Collection $invoices) => round((float) $invoices->sum('amount'), 2));

        $series = [];
        $cursor = $daily ? $from->startOfDay() : $from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $key = $cursor->format($format);

            $series[] = [
                'key' => $key,
                'label' => $cursor->format($daily ? 'j M' : 'M Y'),
                'signups' => $signups[$key] ?? 0,
                'published' => $published[$key] ?? 0,
                'failed' => $failed[$key] ?? 0,
                'invoiced' => (float) $invoiced->get($key, 0.0),
            ];

            $cursor = $daily ? $cursor->addDay() : $cursor->addMonth();
        }

        return $series;
    }

    /**
     * How many of the given timestamps fall in each series bucket (keyed `Y-m-d` or `Y-m`).
     *
     * @param  iterable<mixed>  $dates
     * @return array<string, int>
     */
    private function countByBucket(iterable $dates, string $format): array
    {
        $counts = [];

        foreach ($dates as $date) {
            $key = CarbonImmutable::parse($date)->format($format);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
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

        $invoiced = Invoice::query()
            ->whereIn('org_id', $orgIds)
            ->where('currency', $currency)
            ->whereIn('status', [InvoiceStatus::Sent, InvoiceStatus::Paid])
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
