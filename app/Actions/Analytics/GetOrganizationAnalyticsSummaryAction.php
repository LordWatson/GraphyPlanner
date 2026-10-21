<?php

namespace App\Actions\Analytics;

use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\DailyBrief;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\AI\Images\ImageQuotaGuard;
use App\Services\Analytics\AiUsageMetrics;
use App\Services\Analytics\AnalyticsCache;
use App\Services\Analytics\ApprovalMetrics;
use App\Services\Analytics\EngagementMetrics;
use App\Services\Analytics\PostActivityMetrics;
use App\Services\Analytics\RevenueMetrics;
use App\Services\Analytics\TeamWorkloadMetrics;
use App\Support\Analytics\AnalyticsAccess;
use App\Support\Analytics\AnalyticsScope;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use App\Support\Analytics\SeriesBuckets;
use App\Support\Analytics\SqlDialect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Step 17.3 — one organization's analytics: how clients are tracking, what's going out, where
 * approvals stall, who's carrying the load, what's been billed and what AI is costing. A read layer
 * over tables the app already writes, built on the shared core (Step 17.1) with `[$org->id]` as
 * the scope — no stored aggregates.
 *
 * - **Tenant safety:** the org id comes from the `Organization` passed in (never input), and the
 *   filters' client/campaign ids are already re-checked against it.
 * - **Role scoping per section:** sections the viewer can't see come back `null` (see
 *   `AnalyticsAccess`); `ai.cost_estimate` is `null` without `viewAiCost`. Exports, saved reports
 *   and emails all go through this action with the *requesting* viewer, so they can never contain
 *   more than that person's page would show.
 * - **Org timezone:** ranges and every daily bucket follow `organizations.default_timezone`.
 * - **Money is never summed across currencies.** The series and per-client figures use
 *   `primary_currency` (the org's default when it invoiced in it, else the most-invoiced one).
 * - **Snapshots vs. ranges:** client health, the post pipeline, "scheduled" and "waiting on client"
 *   are right-now figures; everything else is bound to the range.
 * - **Engagement** (Step M-3.1): `EngagementMetrics::summary()` — the latest reading per published
 *   channel in range, follower growth, top posts. Visible to every analytics role (no money).
 */
class GetOrganizationAnalyticsSummaryAction
{
    private const TOP_CLIENTS = 10;

    private const TOP_CAMPAIGNS = 5;

    private const ATTENTION_CLIENTS = 8;

    public function __construct(
        private readonly PostActivityMetrics $postMetrics,
        private readonly RevenueMetrics $revenueMetrics,
        private readonly AiUsageMetrics $aiMetrics,
        private readonly ApprovalMetrics $approvalMetrics,
        private readonly TeamWorkloadMetrics $teamMetrics,
        private readonly ImageQuotaGuard $imageQuota,
        private readonly AnalyticsCache $cache,
        private readonly EngagementMetrics $engagementMetrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Organization $organization, OrganizationAnalyticsFilters $filters, User $viewer): array
    {
        if ($filters->orgId !== $organization->id) {
            throw new \InvalidArgumentException('Analytics filters belong to a different organization.');
        }

        // Step I-2.7: cached per (org, filters, viewer) for five minutes — the viewer is part of
        // the key because sections are role-scoped. See AnalyticsCache for invalidation.
        return $this->cache->remember($organization->id, 'summary', [
            'filters' => $filters->toArray(),
            'viewer' => $viewer->id,
            'role' => $viewer->role?->value,
            'currency' => $organization->default_currency,
        ], fn () => $this->build($organization, $filters, $viewer));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(Organization $organization, OrganizationAnalyticsFilters $filters, User $viewer): array
    {
        $scope = $filters->scope();
        $range = $filters->range;
        $metrics = AnalyticsAccess::visibleMetrics($viewer, $filters);
        $has = fn (string $metric): bool => in_array($metric, $metrics, true);
        $canViewRevenue = AnalyticsAccess::canViewRevenue($viewer, $organization);
        $clientId = $filters->singleClientId();

        $primaryCurrency = $canViewRevenue
            ? $this->revenueMetrics->primaryCurrency($scope, $range, $organization->default_currency)
            : $organization->default_currency;

        // Health is read once per client (Step I-2.5: the stored column) and shared by the
        // clients section and the top list.
        $clients = $this->clients($scope);
        // Step M-3.4: as the viewer may read it — Owner-only reasons never reach anyone else.
        $health = $clients->mapWithKeys(fn (Client $client) => [$client->id => $client->healthFor($viewer)]);

        $summary = [
            'scope' => $clientId === null ? 'organization' : 'client',
            'organization' => [
                'name' => $organization->name,
                'timezone' => $range->timezone,
                'default_currency' => $organization->default_currency,
            ],
            'client' => $clientId === null ? null : $this->clientDetail($clientId, $clients, $health, $viewer),
            'filters' => $filters->toArray(),
            'metrics' => $metrics,
            'clients' => $has('clients') ? $this->clientsSection($clients, $health, $filters) : null,
            'posts' => $has('posts') ? $this->postsSection($scope, $filters) : null,
            'approvals' => $has('approvals') ? $this->approvalMetrics->summary($scope, $range) : null,
            'team' => $has('team') ? $this->teamMetrics->summary($scope, $range) : null,
            'revenue' => $has('revenue') ? $this->revenueSection($scope, $filters, $organization) : null,
            'ai' => $has('ai') ? $this->aiSection($scope, $filters, $organization, $viewer) : null,
            // Step M-3.1 / I-10.1: Phase 12's slot, from the shared engagement read layer.
            'engagement' => $has('engagement') ? $this->engagementMetrics->summary($scope, $range, withBestTimes: $clientId !== null) : null,
            'primary_currency' => $primaryCurrency,
            'series' => $this->series($scope, $filters, $canViewRevenue && $has('revenue'), $primaryCurrency),
            'top_clients' => $clientId === null ? $this->topClients($clients, $health, $scope, $filters, $canViewRevenue && $has('revenue'), $primaryCurrency) : null,
            'top_campaigns' => $clientId === null ? null : $this->campaignRows($scope, $filters, self::TOP_CLIENTS),
        ];

        Log::info('Organization analytics summary built', [
            'org_id' => $organization->id,
            'user_id' => $viewer->id,
            'range' => $range->preset,
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'timezone' => $range->timezone,
            'metrics' => $metrics,
            'client_ids' => $filters->clientIds,
            'campaign_ids' => $filters->campaignIds,
            'platforms' => $filters->platforms,
        ]);

        return $summary;
    }

    /**
     * Every non-archived client in scope (health is materialized on the row, Step I-2.5).
     *
     * @return EloquentCollection<int, Client>
     */
    private function clients(AnalyticsScope $scope): EloquentCollection
    {
        return $scope->applyToClientOwned(Client::query(), 'id')
            ->where('status', '!=', ClientStatus::Archived)
            ->orderBy('name')
            ->get();
    }

    /**
     * The drill-down's client — archived clients included, since their history is still viewable.
     *
     * @param  EloquentCollection<int, Client>  $clients
     * @param  Collection<int, array{status: ClientHealth, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string}>}>  $health
     * @return array{id: int, name: string, status: string, health: string, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string}>}|null
     */
    private function clientDetail(int $clientId, EloquentCollection $clients, Collection $health, User $viewer): ?array
    {
        $client = $clients->firstWhere('id', $clientId) ?? Client::query()->find($clientId);

        if (! $client instanceof Client) {
            return null;
        }

        $result = $health[$client->id] ?? $client->healthFor($viewer);

        return [
            'id' => $client->id,
            'name' => $client->name,
            'status' => $client->status->value,
            'health' => $result['status']->value,
            'reason' => $result['reason'],
            'reasons' => $result['reasons'],
        ];
    }

    /**
     * Roster counts (right now, plus new in range) and the health split — a "right now" snapshot.
     *
     * @param  EloquentCollection<int, Client>  $clients
     * @param  Collection<int, array{status: ClientHealth, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string}>}>  $health
     * @return array<string, mixed>
     */
    private function clientsSection(EloquentCollection $clients, Collection $health, OrganizationAnalyticsFilters $filters): array
    {
        $scope = $filters->scope();
        $byStatus = $clients->countBy(fn (Client $client) => $client->status->value);
        $tones = $health->countBy(fn (array $result) => $result['status']->value);

        $attention = $clients
            ->filter(fn (Client $client) => $health[$client->id]['status'] !== ClientHealth::Green)
            ->sortBy(fn (Client $client) => [$health[$client->id]['status'] === ClientHealth::Red ? 0 : 1, $client->name])
            ->take(self::ATTENTION_CLIENTS)
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => $client->name,
                'health' => $health[$client->id]['status']->value,
                'reason' => $health[$client->id]['reason'],
            ])
            ->values()
            ->all();

        return [
            'total' => $clients->count(),
            'active' => (int) ($byStatus[ClientStatus::Active->value] ?? 0),
            'paused' => (int) ($byStatus[ClientStatus::Paused->value] ?? 0),
            'offboarding' => (int) ($byStatus[ClientStatus::Offboarding->value] ?? 0),
            'new' => $scope->applyToClientOwned(Client::query(), 'id')
                ->whereBetween('created_at', $filters->range->utcBounds())
                ->count(),
            'health' => [
                'green' => (int) ($tones[ClientHealth::Green->value] ?? 0),
                'amber' => (int) ($tones[ClientHealth::Amber->value] ?? 0),
                'red' => (int) ($tones[ClientHealth::Red->value] ?? 0),
            ],
            'attention' => $attention,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function postsSection(AnalyticsScope $scope, OrganizationAnalyticsFilters $filters): array
    {
        $range = $filters->range;
        $outcomes = $this->postMetrics->publishOutcomes($scope, $range);

        $pipeline = $scope->posts()
            ->toBase()
            ->selectRaw('posts.status as status, COUNT(*) as aggregate')
            ->groupBy('posts.status')
            ->pluck('aggregate', 'status');

        $byPlatform = PostTarget::query()
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->whereIn('post_targets.post_id', $this->postMetrics->transitionsInRange($scope, $range, PostStatus::Published)->select('post_activity_logs.post_id'))
            ->when($filters->platforms !== [], fn (Builder $query) => $query->whereIn('social_accounts.platform', $filters->platforms))
            ->toBase()
            ->selectRaw('social_accounts.platform as platform, COUNT(*) as aggregate')
            ->groupBy('social_accounts.platform')
            ->pluck('aggregate', 'platform');

        return [
            'created' => $scope->posts()->whereBetween('posts.created_at', $range->utcBounds())->count(),
            'published' => $outcomes['published'],
            'failed' => $outcomes['failed'],
            'success_rate' => $outcomes['success_rate'],
            'scheduled' => (int) ($pipeline[PostStatus::Scheduled->value] ?? 0),
            'pipeline' => array_map(fn (PostStatus $status) => [
                'status' => $status->value,
                'label' => $status->label(),
                'count' => (int) ($pipeline[$status->value] ?? 0),
            ], PostStatus::cases()),
            'by_platform' => array_values(array_filter(array_map(fn (Platform $platform) => [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'published' => (int) ($byPlatform[$platform->value] ?? 0),
            ], Platform::cases()), fn (array $row) => $filters->platforms === [] || in_array($row['platform'], $filters->platforms, true))),
            'top_campaigns' => $this->campaignRows($scope, $filters, self::TOP_CAMPAIGNS, publishedOnly: true),
        ];
    }

    /**
     * Campaigns by posts published in the range (the org page's top 5, and the client
     * drill-down's top list in place of top clients).
     *
     * @return list<array{id: int, name: string, client_id: int, client_name: string, status: string, published: int, scheduled: int, waiting_on_client: int}>
     */
    private function campaignRows(AnalyticsScope $scope, OrganizationAnalyticsFilters $filters, int $limit, bool $publishedOnly = false): array
    {
        $published = $this->postMetrics->transitionsInRange($scope, $filters->range, PostStatus::Published)
            ->join('posts', 'posts.id', '=', 'post_activity_logs.post_id')
            ->whereNotNull('posts.campaign_id')
            ->toBase()
            ->selectRaw('posts.campaign_id as campaign_id, COUNT(*) as aggregate')
            ->groupBy('posts.campaign_id')
            ->pluck('aggregate', 'campaign_id');

        $now = $scope->posts()
            ->whereNotNull('posts.campaign_id')
            ->whereIn('posts.status', [PostStatus::Scheduled, PostStatus::WaitingClient])
            ->toBase()
            ->selectRaw('posts.campaign_id as campaign_id, posts.status as status, COUNT(*) as aggregate')
            ->groupBy('posts.campaign_id', 'posts.status')
            ->get();

        $current = [];

        foreach ($now as $row) {
            $current[(int) $row->campaign_id][(string) $row->status] = (int) $row->aggregate;
        }

        $campaigns = Campaign::query()
            ->whereIn('org_id', $scope->orgIds)
            ->when($scope->clientIds !== [], fn (Builder $query) => $query->whereIn('client_id', $scope->clientIds))
            ->when($scope->campaignIds !== [], fn (Builder $query) => $query->whereIn('id', $scope->campaignIds))
            ->when($publishedOnly, fn (Builder $query) => $query->whereIn('id', $published->keys()->all()))
            ->with('client:id,name')
            ->get(['id', 'name', 'client_id', 'status']);

        return array_values($campaigns
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'client_id' => $campaign->client_id,
                'client_name' => $campaign->client->name,
                'status' => $campaign->status->value,
                'published' => (int) ($published[$campaign->id] ?? 0),
                'scheduled' => $current[$campaign->id][PostStatus::Scheduled->value] ?? 0,
                'waiting_on_client' => $current[$campaign->id][PostStatus::WaitingClient->value] ?? 0,
            ])
            ->sortBy([['published', 'desc'], ['scheduled', 'desc'], ['name', 'asc']])
            ->take($limit)
            ->values()
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function revenueSection(AnalyticsScope $scope, OrganizationAnalyticsFilters $filters, Organization $organization): array
    {
        $range = $filters->range;
        $overdue = $scope->applyToClientOwned(Invoice::query())->overdue();

        // Average whole days from sending to payment, over invoices paid in the range
        // (Step I-2.7: averaged in SQL).
        $averageDays = $this->revenueMetrics->paid($scope, $range)
            ->whereNotNull('sent_at')
            ->toBase()
            ->select(new Expression('AVG('.SqlDialect::wholeDaysBetween('sent_at', 'paid_at').') as average'))
            ->value('average');

        return [
            ...$this->revenueMetrics->summary($scope, $range),
            'overdue' => $this->revenueMetrics->totalsByCurrency(clone $overdue),
            'overdue_count' => $overdue->count(),
            'average_days_to_pay' => $averageDays === null ? null : (int) round((float) $averageDays),
            'retainers' => $this->retainers($scope, $organization),
            // Campaigns enhancement C-8.3: revenue by campaign (same Owner/Strategist rule).
            'by_campaign' => $this->revenueMetrics->byCampaign($scope, $range),
        ];
    }

    /**
     * Active retainers as a monthly equivalent (quarterly ÷ 3, annual ÷ 12; project-based work
     * isn't a retainer). Retainers carry no currency of their own, so they're the org's default.
     *
     * @return array{monthly: list<array{currency: string, total: float}>, clients: int}
     */
    private function retainers(AnalyticsScope $scope, Organization $organization): array
    {
        $clients = $scope->applyToClientOwned(Client::query(), 'id')
            ->where('status', ClientStatus::Active)
            ->whereNotNull('retainer_amount')
            ->whereIn('billing_cycle', [BillingCycle::Monthly, BillingCycle::Quarterly, BillingCycle::Annual])
            ->get(['id', 'retainer_amount', 'billing_cycle']);

        $monthly = $clients->sum(fn (Client $client) => (float) $client->retainer_amount / match ($client->billing_cycle) {
            BillingCycle::Quarterly => 3,
            BillingCycle::Annual => 12,
            default => 1,
        });

        return [
            'monthly' => $clients->isEmpty() ? [] : [['currency' => $organization->default_currency, 'total' => round($monthly, 2)]],
            'clients' => $clients->count(),
        ];
    }

    /**
     * Generations by kind. Images come from `ai_usage_logs`; text AI (trends, ideas, daily briefs)
     * isn't vendor-logged until Step 2.8, so its successes are the rows it persisted (failures
     * are counted from logs once they exist). Captions are only ever visible through logs.
     *
     * @return array<string, mixed>
     */
    private function aiSection(AnalyticsScope $scope, OrganizationAnalyticsFilters $filters, Organization $organization, User $viewer): array
    {
        $range = $filters->range;
        $logged = $this->aiMetrics->byMethod($scope, $range);

        $family = function (string $prefix) use ($logged): array {
            $total = ['succeeded' => 0, 'failed' => 0];

            foreach ($logged as $method => $counts) {
                if (str_starts_with($method, $prefix)) {
                    $total['succeeded'] += $counts['succeeded'];
                    $total['failed'] += $counts['failed'];
                }
            }

            return $total;
        };

        $persisted = fn (Builder $query): int => $scope->applyToClientOwned($query)
            ->whereBetween('created_at', $range->utcBounds())
            ->count();

        $images = $family('image.');
        $captions = $family('caption');

        $methods = [
            ['method' => 'captions', 'label' => 'Captions', 'succeeded' => $captions['succeeded'], 'failed' => $captions['failed']],
            ['method' => 'trends', 'label' => 'Trends', 'succeeded' => $persisted(AiTrend::query()), 'failed' => $family('trend')['failed']],
            ['method' => 'ideas', 'label' => 'Post ideas', 'succeeded' => $persisted(AiPostIdea::query()), 'failed' => $family('idea')['failed']],
            ['method' => 'daily_brief', 'label' => 'Daily briefs', 'succeeded' => $persisted(DailyBrief::query()), 'failed' => $family('daily_brief')['failed']],
            ['method' => 'images', 'label' => 'Images', 'succeeded' => $images['succeeded'], 'failed' => $images['failed']],
        ];

        $quota = $this->imageQuota->summary($organization);

        return [
            'generated' => array_sum(array_column($methods, 'succeeded')),
            'failed' => array_sum(array_column($methods, 'failed')),
            'by_method' => $methods,
            'images_this_month' => $quota,
            'cost_estimate' => AnalyticsAccess::canViewAiCost($viewer, $organization)
                ? round((float) $this->aiMetrics->calls($scope, $range)->sum('cost_estimate'), 2)
                : null,
        ];
    }

    /**
     * One bucket per day (ranges up to ~2 months) or month, in the org's timezone.
     *
     * @return list<array<string, mixed>>
     */
    private function series(AnalyticsScope $scope, OrganizationAnalyticsFilters $filters, bool $withRevenue, string $currency): array
    {
        $range = $filters->range;
        $buckets = new SeriesBuckets($range);

        // Step I-2.7: counted per bucket in SQL (bucket edges bound in the org timezone).
        $created = $buckets->countByBucketSql($scope->posts()->whereBetween('posts.created_at', $range->utcBounds()), 'posts.created_at');

        $transitions = $buckets->countByBucketAndGroupSql(
            $this->postMetrics->transitionsInRange($scope, $range, [
                PostStatus::Published,
                PostStatus::Failed,
                PostStatus::WaitingClient,
                PostStatus::Approved,
            ]),
            'post_activity_logs.created_at',
            'post_activity_logs.to_status',
        );

        $count = fn (PostStatus $status): array => $transitions[$status->value] ?? [];
        $published = $count(PostStatus::Published);
        $failed = $count(PostStatus::Failed);
        $sent = $count(PostStatus::WaitingClient);
        $approved = $count(PostStatus::Approved);
        $invoiced = $withRevenue
            ? $buckets->sumByBucket($this->revenueMetrics->invoicedPairs($scope, $range, $currency), calendarDates: true)
            : [];

        return array_map(function (array $bucket) use ($created, $published, $failed, $sent, $approved, $invoiced, $withRevenue) {
            $key = $bucket['key'];
            $row = [
                'key' => $key,
                'label' => $bucket['label'],
                'created' => $created[$key] ?? 0,
                'published' => $published[$key] ?? 0,
                'failed' => $failed[$key] ?? 0,
                'sent_for_approval' => $sent[$key] ?? 0,
                'approved' => $approved[$key] ?? 0,
            ];

            if ($withRevenue) {
                $row['invoiced'] = (float) ($invoiced[$key] ?? 0.0);
            }

            return $row;
        }, $buckets->buckets());
    }

    /**
     * The busiest clients in the range, by posts published.
     *
     * @param  EloquentCollection<int, Client>  $clients
     * @param  Collection<int, array{status: ClientHealth, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string}>}>  $health
     * @return list<array<string, mixed>>
     */
    private function topClients(EloquentCollection $clients, Collection $health, AnalyticsScope $scope, OrganizationAnalyticsFilters $filters, bool $withRevenue, string $currency): array
    {
        if ($clients->isEmpty()) {
            return [];
        }

        $published = $this->postMetrics->transitionsInRange($scope, $filters->range, PostStatus::Published)
            ->join('posts', 'posts.id', '=', 'post_activity_logs.post_id')
            ->toBase()
            ->selectRaw('posts.client_id as client_id, COUNT(*) as aggregate')
            ->groupBy('posts.client_id')
            ->pluck('aggregate', 'client_id');

        $current = [];

        foreach ($scope->posts()
            ->whereIn('posts.status', [PostStatus::Scheduled, PostStatus::WaitingClient])
            ->toBase()
            ->selectRaw('posts.client_id as client_id, posts.status as status, COUNT(*) as aggregate')
            ->groupBy('posts.client_id', 'posts.status')
            ->get() as $row) {
            $current[(int) $row->client_id][(string) $row->status] = (int) $row->aggregate;
        }

        $invoiced = $withRevenue
            ? $this->revenueMetrics->issued($scope, $filters->range)
                ->where('currency', $currency)
                ->toBase()
                ->selectRaw('client_id, SUM(amount) as aggregate')
                ->groupBy('client_id')
                ->pluck('aggregate', 'client_id')
            : collect();

        return array_values($clients
            ->map(function (Client $client) use ($health, $published, $current, $invoiced, $withRevenue) {
                $row = [
                    'id' => $client->id,
                    'name' => $client->name,
                    'status' => $client->status->value,
                    'health' => $health[$client->id]['status']->value,
                    'health_reason' => $health[$client->id]['reason'],
                    'published' => (int) ($published[$client->id] ?? 0),
                    'scheduled' => $current[$client->id][PostStatus::Scheduled->value] ?? 0,
                    'waiting_on_client' => $current[$client->id][PostStatus::WaitingClient->value] ?? 0,
                ];

                if ($withRevenue) {
                    $row['invoiced'] = round((float) ($invoiced[$client->id] ?? 0), 2);
                }

                return $row;
            })
            ->sortBy([['published', 'desc'], ['scheduled', 'desc'], ['name', 'asc']])
            ->take(self::TOP_CLIENTS)
            ->values()
            ->all());
    }
}
