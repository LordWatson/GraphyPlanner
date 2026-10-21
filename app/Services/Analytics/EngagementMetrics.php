<?php

namespace App\Services\Analytics;

use App\Enums\Platform;
use App\Enums\PostTargetStatus;
use App\Models\Post;
use App\Models\Scopes\OrganizationScope;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use App\Support\Analytics\SeriesBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step M-3.1 / I-10.1 (Phase 12 Step 12.2) — the shared engagement read layer. Fills the
 * `engagement` section of `GetOrganizationAnalyticsSummaryAction`, campaign KPIs (C-7.1), the
 * campaign results' top posts (C-7.2), wrap-report snapshots (C-7.3), best time to post (M-3.2) and
 * the client monthly report (M-3.3), so every surface reads the same numbers.
 *
 * - **Which channels:** published `post_targets` whose `published_at` falls in the range, on
 *   in-scope posts (org, client, campaign) and platforms. Each channel contributes its **latest**
 *   reading (`latest_metrics_id`), so a post's numbers grow as the vendor reports more.
 * - **Nulls are not zeros.** A total is null when no reading in scope reported it; a platform
 *   without a metrics source (`config/platforms.php`) is listed with `available: false` and the
 *   "not available for {platform}" note.
 * - **Engagement rate** = interactions ÷ (reach, else impressions, else views), summed over the
 *   channels that report both — never averaged per post, so one tiny post can't skew it.
 * - **Followers** come from `social_account_metrics` for the in-scope clients' accounts (campaign
 *   filters don't narrow followers: an account's audience isn't per campaign).
 */
class EngagementMetrics
{
    public const TOP_POSTS = 5;

    private const METRICS = ['impressions', 'reach', 'views', 'likes', 'comments', 'shares', 'saves', 'clicks', 'engagement'];

    public function __construct(private readonly BestTimeService $bestTime) {}

    /**
     * One row per published channel in scope and range, with its latest reading (metric columns
     * are null when it has none).
     *
     * @return Collection<int, \stdClass>
     */
    public function channels(AnalyticsScope $scope, AnalyticsRange $range): Collection
    {
        return DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->leftJoin('post_target_metrics as m', 'm.id', '=', 'post_targets.latest_metrics_id')
            ->where('post_targets.status', PostTargetStatus::Published->value)
            ->whereBetween('post_targets.published_at', $range->utcBounds())
            ->whereIn('posts.id', $scope->posts()->withoutGlobalScope(OrganizationScope::class)->select('posts.id'))
            ->when($scope->platforms !== [], fn ($query) => $query->whereIn('social_accounts.platform', $scope->platforms))
            ->select([
                'post_targets.id as target_id',
                'post_targets.post_id',
                'post_targets.published_at',
                'post_targets.post_url',
                'post_targets.metrics_synced_at',
                'posts.client_id',
                'posts.campaign_id',
                'social_accounts.id as social_account_id',
                'social_accounts.platform',
                'social_accounts.handle',
                'social_accounts.timezone',
                'm.id as metric_id',
                ...array_map(fn (string $metric) => "m.{$metric}", self::METRICS),
            ])
            ->orderBy('post_targets.id')
            ->get();
    }

    /**
     * The `engagement` analytics section.
     *
     * @return array<string, mixed>
     */
    public function summary(AnalyticsScope $scope, AnalyticsRange $range, int $topPosts = self::TOP_POSTS, bool $withBestTimes = false): array
    {
        $channels = $this->channels($scope, $range);
        $measured = $channels->whereNotNull('metric_id');
        $followers = $this->followers($scope, $range);

        return [
            'published_channels' => $channels->count(),
            'measured_channels' => $measured->count(),
            'totals' => $this->totals($measured),
            'engagement_rate' => $this->rate($measured),
            'by_platform' => $this->byPlatform($scope, $channels),
            'followers' => array_diff_key($followers, ['by_day' => true]),
            'series' => $this->series($range, $channels, $followers['by_day']),
            'top_posts' => $this->topPosts($channels, $topPosts),
            'last_synced_at' => $this->lastSynced($scope, $channels),
            // Step M-3.2: per account (the client drill-down only — an org can have hundreds).
            'best_times' => $withBestTimes ? $this->bestTimes($scope) : null,
        ];
    }

    /**
     * Step M-3.2 — best time to post for every in-scope account, from its own engagement.
     *
     * @return list<array<string, mixed>>
     */
    public function bestTimes(AnalyticsScope $scope): array
    {
        $accounts = DB::table('social_accounts')
            ->whereIn('org_id', $scope->orgIds)
            ->whereNull('deleted_at')
            ->when($scope->clientIds !== [], fn ($query) => $query->whereIn('client_id', $scope->clientIds))
            ->when($scope->platforms !== [], fn ($query) => $query->whereIn('platform', $scope->platforms))
            ->orderBy('platform')
            ->orderBy('handle')
            ->get(['id', 'platform', 'handle', 'timezone']);

        $best = $this->bestTime->forAccounts(array_values(array_map('intval', $accounts->pluck('id')->all())));

        return array_values($accounts->map(fn ($account) => [
            'social_account_id' => (int) $account->id,
            'platform' => (string) $account->platform,
            'platform_label' => Platform::tryFrom((string) $account->platform)?->label() ?? (string) $account->platform,
            'handle' => (string) $account->handle,
            'timezone' => (string) $account->timezone,
            ...$best[(int) $account->id],
        ])->all());
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, int|null>
     */
    public function totals(Collection $rows): array
    {
        $totals = [];

        foreach (self::METRICS as $metric) {
            $reported = $rows->pluck($metric)->filter(fn ($value) => $value !== null);
            $totals[$metric] = $reported->isEmpty() ? null : (int) $reported->sum();
        }

        return $totals;
    }

    /**
     * Interactions ÷ audience (reach, else impressions, else views) as a percentage, one decimal.
     *
     * @param  Collection<int, \stdClass>  $rows
     */
    public function rate(Collection $rows): ?float
    {
        $engagement = 0;
        $audience = 0;

        foreach ($rows as $row) {
            $base = $row->reach ?? $row->impressions ?? $row->views;

            if ($row->engagement === null || $base === null || (int) $base <= 0) {
                continue;
            }

            $engagement += (int) $row->engagement;
            $audience += (int) $base;
        }

        return $audience > 0 ? round($engagement / $audience * 100, 2) : null;
    }

    /**
     * Follower counts and growth for the in-scope clients' accounts: per account, the first count
     * captured in the range (else the last before it) against the latest in the range.
     *
     * @return array{current: int|null, growth: int|null, accounts: list<array<string, mixed>>, by_day: array<string, int>}
     */
    public function followers(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        [$fromDate, $toDate] = $range->dateBounds();

        $accounts = DB::table('social_accounts')
            ->whereIn('social_accounts.org_id', $scope->orgIds)
            ->whereNull('social_accounts.deleted_at')
            ->when($scope->clientIds !== [], fn ($query) => $query->whereIn('social_accounts.client_id', $scope->clientIds))
            ->when($scope->platforms !== [], fn ($query) => $query->whereIn('social_accounts.platform', $scope->platforms))
            ->get(['id', 'client_id', 'platform', 'handle']);

        if ($accounts->isEmpty()) {
            return ['current' => null, 'growth' => null, 'accounts' => [], 'by_day' => []];
        }

        $rows = DB::table('social_account_metrics')
            ->whereIn('social_account_id', $accounts->pluck('id'))
            ->whereNotNull('followers')
            ->whereDate('metric_date', '<=', $toDate)
            ->orderBy('metric_date')
            ->get(['social_account_id', 'metric_date', 'followers'])
            ->groupBy('social_account_id');

        $list = [];
        $byDay = [];

        foreach ($accounts as $account) {
            /** @var Collection<int, \stdClass> $history */
            $history = $rows->get($account->id, collect());

            if ($history->isEmpty()) {
                continue;
            }

            $inRange = $history->filter(fn ($row) => substr((string) $row->metric_date, 0, 10) >= $fromDate);
            $before = $history->filter(fn ($row) => substr((string) $row->metric_date, 0, 10) < $fromDate)->last();
            $start = $before ?? $inRange->first();
            $end = $inRange->last() ?? $before;

            if ($end === null) {
                continue;
            }

            $platform = Platform::tryFrom((string) $account->platform);
            $list[] = [
                'social_account_id' => (int) $account->id,
                'client_id' => (int) $account->client_id,
                'platform' => (string) $account->platform,
                'platform_label' => $platform?->label() ?? (string) $account->platform,
                'handle' => (string) $account->handle,
                'followers' => (int) $end->followers,
                'growth' => $inRange->isEmpty() || $start === null ? null : (int) $end->followers - (int) $start->followers,
            ];

            // Carry each account's count forward day by day (for the series).
            $carry = $before === null ? null : (int) $before->followers;
            $points = $inRange->mapWithKeys(fn ($row) => [substr((string) $row->metric_date, 0, 10) => (int) $row->followers]);
            $cursor = CarbonImmutable::parse($fromDate);
            $last = CarbonImmutable::parse($toDate);

            while ($cursor->lessThanOrEqualTo($last)) {
                $key = $cursor->toDateString();
                $carry = $points[$key] ?? $carry;

                if ($carry !== null) {
                    $byDay[$key] = ($byDay[$key] ?? 0) + $carry;
                }

                $cursor = $cursor->addDay();
            }
        }

        ksort($byDay);
        usort($list, fn (array $a, array $b) => [$b['followers'], $a['handle']] <=> [$a['followers'], $b['handle']]);
        $growth = array_filter(array_column($list, 'growth'), fn ($value) => $value !== null);

        return [
            'current' => $list === [] ? null : array_sum(array_column($list, 'followers')),
            'growth' => $growth === [] ? null : array_sum($growth),
            'accounts' => $list,
            'by_day' => $byDay,
        ];
    }

    /**
     * Per platform in scope (has published channels or connected accounts): availability,
     * channels published and measured, and the platform's figures.
     *
     * @param  Collection<int, \stdClass>  $channels
     * @return list<array<string, mixed>>
     */
    public function byPlatform(AnalyticsScope $scope, Collection $channels): array
    {
        $present = DB::table('social_accounts')
            ->whereIn('org_id', $scope->orgIds)
            ->whereNull('deleted_at')
            ->when($scope->clientIds !== [], fn ($query) => $query->whereIn('client_id', $scope->clientIds))
            ->distinct()
            ->pluck('platform')
            ->merge($channels->pluck('platform'))
            ->map(fn ($value) => (string) $value)
            ->unique();

        $rows = [];

        foreach (Platform::cases() as $platform) {
            if (! $present->contains($platform->value) || ($scope->platforms !== [] && ! in_array($platform->value, $scope->platforms, true))) {
                continue;
            }

            $spec = $platform->spec();
            $mine = $channels->where('platform', $platform->value);
            $measured = $mine->whereNotNull('metric_id');
            $totals = $this->totals($measured);

            $rows[] = [
                'platform' => $platform->value,
                'label' => $spec->label,
                'available' => $spec->postMetrics,
                'note' => $spec->postMetrics ? null : "Post engagement isn't available for {$spec->label}.",
                'published' => $mine->count(),
                'measured' => $measured->count(),
                'engagement' => $totals['engagement'],
                'impressions' => $totals['impressions'],
                'reach' => $totals['reach'],
                'engagement_rate' => $this->rate($measured),
            ];
        }

        return $rows;
    }

    /**
     * Posts ranked by interactions (their channels summed), then audience. Only posts with a
     * reading rank.
     *
     * @param  Collection<int, \stdClass>  $channels
     * @return list<array<string, mixed>>
     */
    public function topPosts(Collection $channels, int $limit = self::TOP_POSTS): array
    {
        $ranked = $channels->whereNotNull('metric_id')
            ->groupBy('post_id')
            ->map(fn (Collection $rows, $postId) => [
                'post_id' => (int) $postId,
                'engagement' => $this->totals($rows)['engagement'],
                'audience' => (int) $rows->sum(fn ($row) => $row->reach ?? $row->impressions ?? $row->views ?? 0),
                'rows' => $rows,
            ])
            ->filter(fn (array $row) => $row['engagement'] !== null || $row['audience'] > 0)
            ->sortBy([['engagement', 'desc'], ['audience', 'desc'], ['post_id', 'asc']])
            ->take($limit)
            ->values();

        if ($ranked->isEmpty()) {
            return [];
        }

        $posts = Post::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->with(['assets', 'client:id,name'])
            ->whereIn('id', $ranked->pluck('post_id'))
            ->get()
            ->keyBy('id');

        return array_values($ranked->map(function (array $row) use ($posts) {
            $post = $posts->get($row['post_id']);
            /** @var Collection<int, \stdClass> $rows */
            $rows = $row['rows'];
            $totals = $this->totals($rows);

            return [
                'post_id' => $row['post_id'],
                'client_id' => $post?->client_id,
                'client_name' => $post?->client?->name,
                'caption' => $post?->master_caption ? Str::limit(trim($post->master_caption), 120) : null,
                'thumbnail_url' => $post?->assets->first()?->thumbnailUrl(),
                'published_at' => CarbonImmutable::parse((string) $rows->min('published_at'), 'UTC')->toIso8601String(),
                'platforms' => array_values($rows->pluck('platform')->map(fn ($value) => (string) $value)->unique()->all()),
                'post_url' => $rows->pluck('post_url')->filter()->first(),
                'engagement' => $totals['engagement'],
                'impressions' => $totals['impressions'],
                'reach' => $totals['reach'],
                'views' => $totals['views'],
                'likes' => $totals['likes'],
                'comments' => $totals['comments'],
                'shares' => $totals['shares'],
                'saves' => $totals['saves'],
                'engagement_rate' => $this->rate($rows),
            ];
        })->all());
    }

    /**
     * Interactions and audience by the bucket the channel was published in, plus total followers
     * at the end of each bucket.
     *
     * @param  Collection<int, \stdClass>  $channels
     * @param  array<string, int>  $followersByDay
     * @return list<array{key: string, label: string, engagement: int, impressions: int, followers: int|null}>
     */
    public function series(AnalyticsRange $range, Collection $channels, array $followersByDay): array
    {
        $buckets = new SeriesBuckets($range);
        $measured = $channels->whereNotNull('metric_id');
        $engagement = $buckets->sumByBucket($measured->map(fn ($row) => [$row->published_at, $row->engagement ?? 0])->all());
        $impressions = $buckets->sumByBucket($measured->map(fn ($row) => [$row->published_at, $row->impressions ?? $row->views ?? 0])->all());

        $followers = [];

        foreach ($followersByDay as $date => $count) {
            // The last day in each bucket wins (days arrive in order).
            $followers[$buckets->keyFor($date, calendarDates: true)] = $count;
        }

        return array_map(fn (array $bucket) => [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'engagement' => (int) ($engagement[$bucket['key']] ?? 0),
            'impressions' => (int) ($impressions[$bucket['key']] ?? 0),
            'followers' => $followers[$bucket['key']] ?? null,
        ], $buckets->buckets());
    }

    /**
     * @param  Collection<int, \stdClass>  $channels
     */
    private function lastSynced(AnalyticsScope $scope, Collection $channels): ?string
    {
        $account = DB::table('social_accounts')
            ->whereIn('org_id', $scope->orgIds)
            ->when($scope->clientIds !== [], fn ($query) => $query->whereIn('client_id', $scope->clientIds))
            ->max('metrics_synced_at');

        $latest = collect([$account, $channels->max('metrics_synced_at')])->filter()->max();

        return $latest === null ? null : CarbonImmutable::parse((string) $latest, 'UTC')->toIso8601String();
    }
}
