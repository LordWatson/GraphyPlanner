<?php

namespace App\Services\Analytics;

use App\Enums\PostStatus;
use App\Models\PostActivityLog;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Step 17.1 — post workflow figures read from `post_activity_logs`: a status "happened" at the
 * moment its transition was logged (as the dashboard's publish reliability chart counts it), never
 * from `posts.updated_at`. Shared by the platform console and organization analytics.
 */
class PostActivityMetrics
{
    /**
     * Transitions into the given status(es) for the in-scope posts (any time). Note rows that
     * don't change status (e.g. "Added 2 AI images", where `from_status = to_status`) are not
     * transitions and never count.
     *
     * @param  PostStatus|list<PostStatus>  $status
     * @return Builder<PostActivityLog>
     */
    public function transitions(AnalyticsScope $scope, PostStatus|array $status): Builder
    {
        $statuses = is_array($status) ? $status : [$status];

        return self::realTransitions(PostActivityLog::query())
            ->when(
                count($statuses) === 1,
                fn (Builder $query) => $query->where('post_activity_logs.to_status', $statuses[0]),
                fn (Builder $query) => $query->whereIn('post_activity_logs.to_status', $statuses),
            )
            ->whereIn('post_activity_logs.post_id', $scope->posts()->select('posts.id'));
    }

    /**
     * Only rows that actually moved a post between statuses.
     *
     * @param  Builder<PostActivityLog>  $query
     * @return Builder<PostActivityLog>
     */
    public static function realTransitions(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('post_activity_logs.from_status')
            ->orWhereColumn('post_activity_logs.from_status', '!=', 'post_activity_logs.to_status'));
    }

    /**
     * Transitions into the given status(es) inside the range.
     *
     * @param  PostStatus|list<PostStatus>  $status
     * @return Builder<PostActivityLog>
     */
    public function transitionsInRange(AnalyticsScope $scope, AnalyticsRange $range, PostStatus|array $status): Builder
    {
        return $this->transitions($scope, $status)->whereBetween('post_activity_logs.created_at', $range->utcBounds());
    }

    /**
     * @return array{published: int, failed: int, success_rate: float|null}
     */
    public function publishOutcomes(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        $published = $this->transitionsInRange($scope, $range, PostStatus::Published)->count();
        $failed = $this->transitionsInRange($scope, $range, PostStatus::Failed)->count();

        return [
            'published' => $published,
            'failed' => $failed,
            'success_rate' => self::successRate($published, $failed),
        ];
    }

    public static function successRate(int $published, int $failed): ?float
    {
        return $published + $failed > 0 ? round($published / ($published + $failed) * 100, 1) : null;
    }

    /**
     * The `to_status` + `created_at` of every in-range transition into the given statuses — one
     * query feeding every per-bucket workflow line of a series.
     *
     * @param  list<PostStatus>  $statuses
     * @return Collection<int, PostActivityLog>
     */
    public function transitionTimes(AnalyticsScope $scope, AnalyticsRange $range, array $statuses): Collection
    {
        return $this->transitionsInRange($scope, $range, $statuses)->get(['to_status', 'created_at']);
    }
}
