<?php

namespace App\Actions\Home;

use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Campaigns\Health\LaunchingWithUnapprovedWorkRule;
use App\Services\Grids\GridOrderRiskService;
use App\Services\PostApprovalSlaService;
use App\Support\Campaigns\CampaignClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class GetNeedsAttentionItemsAction
{
    /**
     * Rows returned per bucket. The bucket's `total` is always the full count.
     */
    public const DEFAULT_LIMIT = 20;

    /**
     * Statuses a post can still be worked on in — the "missing media" bucket only looks at these.
     *
     * @var list<PostStatus>
     */
    private const ACTIVE_STATUSES = [
        PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview,
        PostStatus::WaitingClient, PostStatus::ChangesRequested, PostStatus::Approved,
    ];

    /**
     * Build the Step 0.12 "Home / needs-attention" payload: failed publishes, posts waiting on
     * internal (studio) review, posts waiting on client approval, posts missing required media, and
     * disconnected social accounts. Every row carries enough context (client, platform/handle) plus
     * a `post_id` (or `client_id` for account rows, which have no single post) so the UI can link
     * straight to the relevant post.
     *
     * Step I-2.2: every bucket is one bounded query (`limit`) plus a count, so the query count is
     * fixed no matter how many posts the org has. "Missing media" is the SQL form of the
     * `EvaluatePostChecklistAction` media rule: no assets, and either no targets or at least one
     * target on a platform outside `Platform::textOnlyCapableValues()`.
     *
     * Scoped like `CalendarController`/`GetUpcomingPostOccurrencesAction`: a `Role::ClientReviewer`
     * only ever sees their own client's rows.
     *
     * @return array{
     *     failedPublishes: array{items: list<array<string, mixed>>, total: int},
     *     internalReviews: array{items: list<array<string, mixed>>, total: int},
     *     waitingApprovals: array{items: list<array<string, mixed>>, total: int, overdue: int},
     *     missingMedia: array{items: list<array<string, mixed>>, total: int},
     *     campaigns: array{items: list<array<string, mixed>>, total: int},
     *     gridRisks: array{items: list<array<string, mixed>>, total: int},
     *     recyclingSuggestions: array{items: list<array<string, mixed>>, total: int},
     *     disconnectedAccounts: array{items: list<array<string, mixed>>, total: int},
     * }
     */
    public function __invoke(User $user, int $limit = self::DEFAULT_LIMIT): array
    {
        $statusCounts = $this->posts($user)
            ->whereIn('status', [PostStatus::Failed, PostStatus::PartiallyPublished, PostStatus::InternalReview, PostStatus::WaitingClient])
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = fn (PostStatus ...$statuses): int => (int) collect($statuses)->sum(fn (PostStatus $status) => $statusCounts[$status->value] ?? 0);

        // Step M-6.5: which waiting-on-client posts are past the org's approval SLA (overdue).
        $overdueWaiting = app(PostApprovalSlaService::class)
            ->overdueQuery($user->organization)
            ->where('status', PostStatus::WaitingClient->value);
        $overdueIds = (clone $overdueWaiting)->pluck('id')->all();
        $overdueTotal = (clone $overdueWaiting)->count();

        $waitingApprovals = array_map(
            fn (array $row) => [...$row, 'overdue' => in_array($row['post_id'], $overdueIds, true)],
            $this->rows($this->posts($user)->where('status', PostStatus::WaitingClient), $limit, fn () => 'Waiting on client approval'),
        );

        $missingMediaQuery = $this->missingMedia($this->posts($user));

        $accountsQuery = SocialAccount::query()
            ->where('org_id', $user->org_id)
            // Step I-3.9 / M-2.7: plus connections expiring within a week.
            ->where(fn (Builder $query) => $query
                ->whereIn('connection_status', [ConnectionStatus::NotConnected, ConnectionStatus::TokenExpired])
                ->orWhere(fn (Builder $expiring) => $expiring
                    ->whereNotNull('meta_access_token')
                    ->where('meta_access_token_expires_at', '<=', now()->addDays(SocialAccount::TOKEN_WARNING_DAYS))))
            ->when($user->role === Role::ClientReviewer, fn (Builder $query) => $query->where('client_id', $user->client_id));

        return [
            'failedPublishes' => [
                'items' => $this->rows(
                    $this->posts($user)->whereIn('status', [PostStatus::Failed, PostStatus::PartiallyPublished]),
                    $limit,
                    // Step I-1.3: a partial publish needs the same attention (retry the failed channels).
                    fn (Post $post) => $post->status === PostStatus::PartiallyPublished ? 'Partly published — some channels failed' : 'Publish failed',
                ),
                'total' => $count(PostStatus::Failed, PostStatus::PartiallyPublished),
            ],
            'internalReviews' => [
                'items' => $this->rows($this->posts($user)->where('status', PostStatus::InternalReview), $limit, fn () => 'Waiting on internal review'),
                'total' => $count(PostStatus::InternalReview),
            ],
            'waitingApprovals' => [
                'items' => $waitingApprovals,
                'total' => $count(PostStatus::WaitingClient),
                // Step M-6.5: how many of those are past the approval SLA.
                'overdue' => $overdueTotal,
            ],
            'missingMedia' => [
                'items' => $this->rows(clone $missingMediaQuery, $limit, fn () => 'Missing required media'),
                'total' => $missingMediaQuery->count(),
            ],
            // Campaigns enhancement C-1.4 — campaigns in trouble, found in SQL (a fixed number of
            // queries however many campaigns there are).
            'campaigns' => $user->role === Role::ClientReviewer
                ? ['items' => [], 'total' => 0]
                : $this->campaigns($user, $limit),
            // Step 18.11: grids whose order is at risk (a post failed or left the schedule while
            // later tiles are still scheduled). Flagged by `GridOrderRiskService`, re-checked here.
            'gridRisks' => $user->role === Role::ClientReviewer
                ? ['items' => [], 'total' => 0]
                : $this->gridRisks($user, $limit),
            // Step M-5.8 (Phase 11): evergreen posts past their recycle window and not yet recycled.
            'recyclingSuggestions' => $user->role === Role::ClientReviewer
                ? ['items' => [], 'total' => 0]
                : $this->recyclingSuggestions($user, $limit),
            'disconnectedAccounts' => [
                'items' => array_values((clone $accountsQuery)
                    ->with('client:id,name')
                    ->orderBy('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (SocialAccount $account) => [
                        'account_id' => $account->id,
                        'client_id' => $account->client_id,
                        'client_name' => $account->client?->name,
                        'platform' => $account->platform->value,
                        'platform_label' => $account->platform->label(),
                        'handle' => $account->handle,
                        'connection_status' => $account->connection_status->value,
                        'connection_status_label' => $account->connection_status->label(),
                        // Step M-2.7: why it's here when it is still connected.
                        'reason' => in_array($account->connection_status, [ConnectionStatus::NotConnected, ConnectionStatus::TokenExpired], true)
                            ? null
                            : ucfirst('connection '.$account->tokenExpiryPhrase()),
                        'token_expires_at' => $account->meta_access_token_expires_at?->toIso8601String(),
                    ])
                    ->all()),
                'total' => $accountsQuery->count(),
            ],
        ];
    }

    /**
     * Step M-5.8 (Phase 11.2) — evergreen posts ready to repost: flagged `is_recyclable`, published,
     * past their `recycle_after_days` window (measured from the latest channel's publish time), and
     * not yet recycled (`recycledInto` empty). Candidates are few (recycling is opt-in per post), so
     * the window is applied in PHP over a bounded set to stay portable across MySQL and SQLite.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function recyclingSuggestions(User $user, int $limit): array
    {
        $candidates = $this->posts($user)
            ->where('is_recyclable', true)
            ->whereNotNull('recycle_after_days')
            ->where('status', PostStatus::Published)
            ->whereDoesntHave('recycledInto')
            ->whereHas('targets', fn (Builder $target) => $target->whereNotNull('published_at'))
            ->with(['client:id,name', 'targets:id,post_id,social_account_id,published_at', 'targets.socialAccount:id,platform,handle'])
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'org_id', 'client_id', 'status', 'master_caption', 'recycle_after_days']);

        $due = $candidates->filter(function (Post $post) {
            $publishedAt = $post->targets->max('published_at');

            return $publishedAt instanceof CarbonInterface
                && $publishedAt->copy()->addDays((int) $post->recycle_after_days)->lte(now());
        })->values();

        return [
            'items' => array_values($due->take($limit)->map(fn (Post $post) => [
                ...$this->transformPost($post, 'Ready to recycle'),
                'recycle_after_days' => (int) $post->recycle_after_days,
            ])->all()),
            'total' => $due->count(),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function gridRisks(User $user, int $limit): array
    {
        $risk = app(GridOrderRiskService::class);

        $items = GridPlan::query()
            ->where('org_id', $user->org_id)
            ->whereNotNull('order_risk_notified_at')
            ->whereIn('client_id', Client::query()->select('id'))
            ->with('client:id,name')
            ->orderBy('order_risk_notified_at')
            ->limit($limit)
            ->get()
            ->map(function (GridPlan $plan) use ($risk) {
                $atRisk = $risk->atRisk($plan);

                return $atRisk === null ? null : [
                    'grid_plan_id' => $plan->id,
                    'name' => $plan->name,
                    'client_id' => $plan->client_id,
                    'client_name' => $plan->client->name,
                    'reason' => $atRisk['reason'],
                    'later_count' => count($atRisk['later_post_ids']),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return ['items' => array_values($items), 'total' => count($items)];
    }

    /**
     * Step C-1.4 — open campaigns that need a hand, each with the reason in words:
     *
     * - still Active/Paused after its end date ("Ended 3 days ago, still Active.");
     * - launching within 3 days (org timezone) with work not yet approved;
     * - a scheduled post going to an account whose token expired.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function campaigns(User $user, int $limit): array
    {
        $today = CampaignClock::today($user->organization);
        $date = $today->toDateString();
        $soon = $today->addDays(LaunchingWithUnapprovedWorkRule::DAYS)->toDateString();

        $unapprovedPosts = fn ($query) => $query->select(DB::raw(1))
            ->from('posts')
            ->whereColumn('posts.campaign_id', 'campaigns.id')
            ->whereNull('posts.deleted_at')
            ->whereIn('posts.status', array_map(fn (PostStatus $status) => $status->value, PostStatus::preApproval()));

        $unstarted = fn ($query) => $query->select(DB::raw(1))
            ->from('campaign_deliverables')
            ->whereColumn('campaign_deliverables.campaign_id', 'campaigns.id')
            ->whereNull('campaign_deliverables.post_id');

        $expired = fn ($query) => $query->select(DB::raw(1))
            ->from('posts')
            ->join('post_targets', 'post_targets.post_id', '=', 'posts.id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->whereColumn('posts.campaign_id', 'campaigns.id')
            ->whereNull('posts.deleted_at')
            ->where('posts.status', PostStatus::Scheduled->value)
            ->where('social_accounts.connection_status', ConnectionStatus::TokenExpired->value);

        $query = Campaign::query()
            ->where('campaigns.org_id', $user->org_id)
            ->whereIn('campaigns.client_id', Client::query()->select('id'))
            ->whereIn('campaigns.status', [CampaignStatus::Planning, CampaignStatus::Active, CampaignStatus::Paused])
            ->where(fn (Builder $where) => $where
                ->where(fn (Builder $ended) => $ended->whereIn('campaigns.status', [CampaignStatus::Active, CampaignStatus::Paused])->where('campaigns.end_date', '<', $date))
                ->orWhere(fn (Builder $launch) => $launch->whereIn('campaigns.status', [CampaignStatus::Planning, CampaignStatus::Active])
                    ->whereBetween('campaigns.start_date', [$date, $soon])
                    ->where(fn (Builder $work) => $work->whereExists($unapprovedPosts)->orWhereExists($unstarted)))
                ->orWhereExists($expired));

        $total = (clone $query)->count();

        $rows = $query
            ->with('client:id,name')
            ->select('campaigns.*')
            ->addSelect(['has_expired_account' => $this->subquery($expired)->limit(1)])
            ->orderBy('campaigns.start_date')
            ->limit($limit)
            ->get();

        return [
            'items' => array_values($rows->map(function (Campaign $campaign) use ($today) {
                $reason = match (true) {
                    (bool) $campaign->getAttribute('has_expired_account') => 'A scheduled post goes to an account that needs reconnecting.',
                    $campaign->end_date !== null && $campaign->end_date->toDateString() < $today->toDateString() => 'Ended '.$this->ago($campaign, $today).', still '.$campaign->status->label().'.',
                    default => 'Launches '.$this->when($campaign, $today).' with work not yet approved.',
                };

                return [
                    'campaign_id' => $campaign->id,
                    'name' => $campaign->name,
                    'code' => $campaign->code,
                    'colour' => $campaign->colour?->value,
                    'client_id' => $campaign->client_id,
                    'client_name' => $campaign->client?->name,
                    'status' => $campaign->status->value,
                    'status_label' => $campaign->status->label(),
                    'reason' => $reason,
                ];
            })->all()),
            'total' => $total,
        ];
    }

    private function ago(Campaign $campaign, CarbonImmutable $today): string
    {
        $days = (int) CarbonImmutable::parse((string) $campaign->end_date?->toDateString())->diffInDays($today);

        return $days === 1 ? '1 day ago' : "{$days} days ago";
    }

    private function when(Campaign $campaign, CarbonImmutable $today): string
    {
        $days = (int) $today->diffInDays(CarbonImmutable::parse((string) $campaign->start_date?->toDateString()));

        return match ($days) {
            0 => 'today',
            1 => 'tomorrow',
            default => "in {$days} days",
        };
    }

    /**
     * @param  callable(QueryBuilder): mixed  $subquery
     */
    private function subquery(callable $subquery): QueryBuilder
    {
        $query = DB::query();
        $subquery($query);

        return $query;
    }

    /**
     * @return Builder<Post>
     */
    private function posts(User $user): Builder
    {
        return Post::query()
            ->forActiveClients()
            ->where('org_id', $user->org_id)
            ->when($user->role === Role::ClientReviewer, fn (Builder $query) => $query->where('client_id', $user->client_id));
    }

    /**
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private function missingMedia(Builder $query): Builder
    {
        return $query
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereDoesntHave('assets')
            ->where(fn (Builder $query) => $query
                ->whereDoesntHave('targets')
                ->orWhereHas('targets.socialAccount', fn (Builder $account) => $account->whereNotIn('platform', Platform::textOnlyCapableValues())));
    }

    /**
     * @param  Builder<Post>  $query
     * @param  callable(Post): string  $reason
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query, int $limit, callable $reason): array
    {
        return array_values($query
            ->with(['client:id,name', 'targets:id,post_id,social_account_id', 'targets.socialAccount:id,platform,handle'])
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'org_id', 'client_id', 'status', 'master_caption'])
            ->map(fn (Post $post) => $this->transformPost($post, $reason($post)))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPost(Post $post, string $reason): array
    {
        $target = $post->targets->first();
        $account = $target?->socialAccount;

        return [
            'post_id' => $post->id,
            'client_id' => $post->client_id,
            'client_name' => $post->client?->name,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'reason' => $reason,
            'master_caption' => $post->master_caption,
            'platform' => $account?->platform?->value,
            'handle' => $account?->handle,
        ];
    }
}
