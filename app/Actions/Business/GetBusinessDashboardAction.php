<?php

namespace App\Actions\Business;

use App\Actions\AI\GenerateDailyIdeaAction;
use App\Actions\Home\GetNeedsAttentionItemsAction;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\Business\PostingGoalGapService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Step B-4.3 — the data behind the business "Today" dashboard. Everything is counted/bounded in SQL
 * (Step I-2.2): a fixed number of queries whatever the brand's history, each section limited to the
 * rows the page shows. Scoped to the workspace's single primary brand, with every "today"/window
 * boundary resolved in the brand's own timezone.
 *
 * Sections:
 *  - **upNext** — the next 5 scheduled channel slots (platform, local time, thumbnail);
 *  - **gaps** — days in the next 14 the brand means to post on (its posting goal, B-4.5) but hasn't;
 *  - **needsYou** — failed publishes, accounts needing a reconnect, posts in review, stale drafts;
 *  - **thisWeek** — publishing consistency available today (richer engagement stats arrive with B-8);
 *  - **ideaOfTheDay** — today's cached local/seasonal idea (B-6.1); null until the nightly run fills it.
 */
class GetBusinessDashboardAction
{
    /** Rows shown per "needs you" bucket and in "up next". */
    private const LIMIT = 5;

    /** A draft untouched for this many days is surfaced as needing a decision. */
    private const STALE_DRAFT_DAYS = 7;

    /** The window "gaps" looks ahead over. */
    private const GAP_WINDOW_DAYS = 14;

    public function __construct(
        private readonly GetNeedsAttentionItemsAction $needsAttention,
        private readonly PostingGoalGapService $gapService,
        private readonly GenerateDailyIdeaAction $dailyIdea,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user, Client $brand, string $timezone): array
    {
        $now = Carbon::now();

        return [
            'brand' => ['id' => $brand->id, 'name' => $brand->name],
            'upNext' => $this->upNext($brand, $timezone, $now),
            'gaps' => $this->gaps($brand, $timezone, $now),
            'needsYou' => $this->needsYou($user, $brand),
            'thisWeek' => $this->thisWeek($brand, $now),
            // Step B-6.1: today's cached local/seasonal idea (filled by the nightly
            // business:generate-daily-ideas run). Read-only here — the page never triggers a vendor
            // call; the empty state links to "Get ideas" when nothing is cached yet.
            'ideaOfTheDay' => $this->dailyIdea->cached($brand, $timezone),
        ];
    }

    /**
     * The next scheduled channel slots, soonest first, each in the brand's local time with a
     * thumbnail (cover image, else the first attached asset).
     *
     * @return list<array<string, mixed>>
     */
    private function upNext(Client $brand, string $timezone, Carbon $now): array
    {
        $targets = PostTarget::query()
            ->whereNotNull('scheduled_at_utc')
            ->where('scheduled_at_utc', '>=', $now)
            ->whereHas('post', fn (Builder $post) => $this->scopedPosts($post, $brand)->where('status', PostStatus::Scheduled))
            ->with([
                'socialAccount:id,platform,handle,display_name',
                'post:id,master_caption,cover_asset_id',
                'post.coverAsset',
                'post.assets',
            ])
            ->orderBy('scheduled_at_utc')
            ->limit(self::LIMIT)
            ->get();

        return array_values($targets->map(function (PostTarget $target) use ($timezone) {
            $utc = $target->scheduled_at_utc;
            $local = $utc?->clone()->setTimezone($timezone);
            $account = $target->socialAccount;
            $post = $target->post;

            return [
                'post_id' => $target->post_id,
                'target_id' => $target->id,
                'platform' => $account?->platform?->value,
                'platform_label' => $account?->platform?->label(),
                'handle' => $account?->handle,
                'caption' => $post?->master_caption ? Str::limit($post->master_caption, 80) : null,
                'thumbnail_url' => $this->thumbnail($post),
                'date' => $local?->toDateString(),
                'time' => $local?->format('H:i'),
                'scheduled_at_utc' => $utc?->toIso8601String(),
            ];
        })->all());
    }

    /**
     * Days in the next {@see self::GAP_WINDOW_DAYS} the brand targets (its posting goal) with nothing
     * scheduled. Empty when the brand hasn't set a goal — no goal, no nagging.
     *
     * @return array{days: list<array{date: string, weekday: int, label: string}>, count: int, goalDays: list<int>, hasGoal: bool}
     */
    private function gaps(Client $brand, string $timezone, Carbon $now): array
    {
        $goal = $brand->postingGoal();

        if (! $goal->isSet()) {
            return ['days' => [], 'count' => 0, 'goalDays' => [], 'hasGoal' => false];
        }

        $windowEnd = $now->clone()->setTimezone($timezone)->startOfDay()->addDays(self::GAP_WINDOW_DAYS);

        $scheduledLocalDates = PostTarget::query()
            ->whereNotNull('scheduled_at_utc')
            ->where('scheduled_at_utc', '>=', $now)
            ->where('scheduled_at_utc', '<', $windowEnd->clone()->utc())
            ->whereHas('post', fn (Builder $post) => $this->scopedPosts($post, $brand)
                ->whereIn('status', [PostStatus::Scheduled, PostStatus::Published, PostStatus::PartiallyPublished]))
            ->pluck('scheduled_at_utc')
            ->map(fn (CarbonInterface $utc) => $utc->clone()->setTimezone($timezone)->toDateString())
            ->unique()
            ->values();

        $scheduledLocalDates = array_values($scheduledLocalDates->all());

        return [
            ...$this->gapService->gaps($goal, $timezone, $now, self::GAP_WINDOW_DAYS, $scheduledLocalDates),
            'hasGoal' => true,
        ];
    }

    /**
     * What needs a decision: failed/partly-published posts, accounts needing a reconnect, posts in
     * review, and drafts left untouched for a week. Reuses the shared needs-attention engine (the
     * business org has one brand, so org-scoped already means brand-scoped) and adds stale drafts.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function needsYou(User $user, Client $brand): array
    {
        $buckets = ($this->needsAttention)($user, self::LIMIT);

        $rows = [];

        foreach ($buckets['failedPublishes']['items'] as $post) {
            $rows[] = $this->postRow($post, 'failed', __('Publish failed — take a look.'));
        }

        foreach ($buckets['disconnectedAccounts']['items'] as $account) {
            $rows[] = [
                'key' => 'account-'.$account['account_id'],
                'type' => 'disconnected',
                'title' => '@'.$account['handle'],
                'subtitle' => $account['reason'] ?? __(':platform needs reconnecting.', ['platform' => $account['platform_label']]),
                'post_id' => null,
                'account_id' => $account['account_id'],
            ];
        }

        foreach ($buckets['internalReviews']['items'] as $post) {
            $rows[] = $this->postRow($post, 'review', __('Waiting on your review.'));
        }

        $staleDrafts = $this->staleDrafts($brand);

        foreach ($staleDrafts['items'] as $post) {
            $rows[] = $this->postRow($post, 'stale_draft', __('A draft from over a week ago.'));
        }

        return [
            'items' => array_slice($rows, 0, 8),
            'total' => $buckets['failedPublishes']['total']
                + $buckets['disconnectedAccounts']['total']
                + $buckets['internalReviews']['total']
                + $staleDrafts['total'],
        ];
    }

    /**
     * Drafts untouched for a week — easy to forget, so the dashboard nudges them.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function staleDrafts(Client $brand): array
    {
        $cutoff = now()->subDays(self::STALE_DRAFT_DAYS);

        $query = Post::query()
            ->where('org_id', $brand->org_id)
            ->where('client_id', $brand->id)
            ->where('status', PostStatus::Draft)
            ->where('updated_at', '<', $cutoff);

        $items = (clone $query)
            ->orderBy('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'client_id', 'status', 'master_caption'])
            ->map(fn (Post $post) => [
                'post_id' => $post->id,
                'master_caption' => $post->master_caption,
                'status' => $post->status->value,
            ])
            ->all();

        return ['items' => array_values($items), 'total' => (clone $query)->count()];
    }

    /**
     * Publishing consistency available today: posts published in the last 7 days, scheduled in the
     * next 7. Richer reach/engagement stats arrive with Phase B-8, flagged by `hasMetrics`.
     *
     * @return array{publishedLast7: int, scheduledNext7: int, hasMetrics: bool}
     */
    private function thisWeek(Client $brand, Carbon $now): array
    {
        $publishedLast7 = PostTarget::query()
            ->where('status', PostTargetStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $now->clone()->subDays(7))
            ->whereHas('post', fn (Builder $post) => $this->scopedPosts($post, $brand))
            ->count();

        $scheduledNext7 = PostTarget::query()
            ->whereNotNull('scheduled_at_utc')
            ->where('scheduled_at_utc', '>=', $now)
            ->where('scheduled_at_utc', '<', $now->clone()->addDays(7))
            ->whereHas('post', fn (Builder $post) => $this->scopedPosts($post, $brand)->where('status', PostStatus::Scheduled))
            ->count();

        return [
            'publishedLast7' => $publishedLast7,
            'scheduledNext7' => $scheduledNext7,
            // No engagement metrics until Phase B-8 / Step I-10.1.
            'hasMetrics' => false,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopedPosts(Builder $query, Client $brand): Builder
    {
        return $query->where('org_id', $brand->org_id)->where('client_id', $brand->id);
    }

    private function thumbnail(?Post $post): ?string
    {
        if ($post === null) {
            return null;
        }

        if ($post->coverAsset !== null) {
            return $post->coverAsset->thumbnailUrl() ?? $post->coverAsset->url;
        }

        $first = $post->assets->first();

        return $first !== null ? ($first->thumbnailUrl() ?? $first->url) : null;
    }

    /**
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function postRow(array $post, string $type, string $subtitle): array
    {
        return [
            'key' => 'post-'.$type.'-'.$post['post_id'],
            'type' => $type,
            'title' => $post['master_caption'] ? Str::limit($post['master_caption'], 60) : __('Untitled post'),
            'subtitle' => $subtitle,
            'post_id' => $post['post_id'],
            'account_id' => null,
        ];
    }
}
