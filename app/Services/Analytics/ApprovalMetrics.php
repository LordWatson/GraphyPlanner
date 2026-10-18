<?php

namespace App\Services\Analytics;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use App\Support\Analytics\SqlDialect;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step 17.3 — where approvals stall, read from `post_activity_logs` transitions:
 *
 * - **Client turnaround**: hours from a post entering `waiting_client` to the client's decision
 *   (the very next transition, when it's `approved` or `changes_requested`). Counted when the
 *   decision lands in the range, even if the post was sent before it.
 * - **Internal turnaround**: hours from entering `internal_review` to whatever came next.
 * - **First-time approval rate**: of the posts first approved in the range, the share approved
 *   without ever going through `changes_requested` before.
 *
 * Step I-2.7: the pairing of each transition with the one before it happens in SQL (window
 * functions, standard on MySQL 8 and SQLite); only the in-range durations reach PHP, where the
 * median/p90 is taken (MySQL has no `percentile_cont`). Phase 9's SLA can mark these overdue
 * later without changing the shape.
 */
class ApprovalMetrics
{
    public function __construct(private readonly PostActivityMetrics $postMetrics) {}

    /**
     * @return array{sent_to_client: int, approved: int, changes_requested: int, first_time_approval_rate: float|null, client_turnaround: array{decisions: int, median_hours: float|null, p90_hours: float|null}, internal_turnaround: array{reviews: int, median_hours: float|null, p90_hours: float|null}, waiting_now: int, oldest_waiting: array{post_id: int, client_id: int, client_name: string, caption: string|null, since: string, hours_waiting: float}|null}
     */
    public function summary(AnalyticsScope $scope, AnalyticsRange $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$clientHours, $internalHours, $firstApprovals, $cleanFirstApprovals] = $this->walk($scope, $range);

        return [
            'sent_to_client' => $this->postMetrics->transitionsInRange($scope, $range, PostStatus::WaitingClient)->count(),
            'approved' => $this->postMetrics->transitionsInRange($scope, $range, PostStatus::Approved)->count(),
            'changes_requested' => $this->postMetrics->transitionsInRange($scope, $range, PostStatus::ChangesRequested)->count(),
            'first_time_approval_rate' => $firstApprovals > 0 ? round($cleanFirstApprovals / $firstApprovals * 100, 1) : null,
            'client_turnaround' => [
                'decisions' => count($clientHours),
                'median_hours' => self::percentile($clientHours, 50),
                'p90_hours' => self::percentile($clientHours, 90),
            ],
            'internal_turnaround' => [
                'reviews' => count($internalHours),
                'median_hours' => self::percentile($internalHours, 50),
                'p90_hours' => self::percentile($internalHours, 90),
            ],
            'waiting_now' => $scope->posts()->where('status', PostStatus::WaitingClient)->count(),
            'oldest_waiting' => $this->oldestWaiting($scope, $now),
        ];
    }

    /**
     * Step I-2.7: one windowed query over the transitions of every post with activity in the
     * range. Per post, ordered by (`created_at`, `id`): `LAG` gives the previous status and its
     * time, and running `SUM()`s over the preceding rows say whether the post was approved or sent
     * back before. Only the in-range rows come back, already paired — the history before the range
     * is read by the window, never shipped to PHP.
     *
     * @return array{0: list<float>, 1: list<float>, 2: int, 3: int}
     */
    private function walk(AnalyticsScope $scope, AnalyticsRange $range): array
    {
        [$from, $to] = $range->utcBounds();

        $activePosts = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_id', $scope->posts()->select('posts.id'))
            ->whereBetween('created_at', [$from, $to])
            ->select('post_id');

        $window = 'OVER (PARTITION BY post_activity_logs.post_id ORDER BY post_activity_logs.created_at, post_activity_logs.id';
        $before = $window.' ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING)';

        $transitions = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_activity_logs.post_id', $activePosts)
            ->where('post_activity_logs.created_at', '<=', $to)
            ->toBase()
            ->select(['post_activity_logs.to_status', 'post_activity_logs.created_at'])
            ->selectRaw("LAG(post_activity_logs.to_status) {$window}) as prev_status")
            ->selectRaw("LAG(post_activity_logs.created_at) {$window}) as prev_at")
            ->selectRaw("SUM(CASE WHEN post_activity_logs.to_status = ? THEN 1 ELSE 0 END) {$before} as approvals_before", [PostStatus::Approved->value])
            ->selectRaw("SUM(CASE WHEN post_activity_logs.to_status = ? THEN 1 ELSE 0 END) {$before} as changes_before", [PostStatus::ChangesRequested->value]);

        $rows = DB::query()
            ->fromSub($transitions, 'transitions')
            ->whereBetween('created_at', [$from, $to])
            ->select(['prev_status', 'to_status', 'approvals_before', 'changes_before'])
            ->addSelect(new Expression(SqlDialect::secondsBetween('prev_at', 'created_at').' as seconds'))
            ->get();

        $clientHours = [];
        $internalHours = [];
        $firstApprovals = 0;
        $cleanFirstApprovals = 0;

        foreach ($rows as $row) {
            if ($row->prev_status !== null) {
                $hours = round(max(0, (int) $row->seconds) / 3600, 1);

                if ($row->prev_status === PostStatus::WaitingClient->value
                    && in_array($row->to_status, [PostStatus::Approved->value, PostStatus::ChangesRequested->value], true)) {
                    $clientHours[] = $hours;
                }

                if ($row->prev_status === PostStatus::InternalReview->value) {
                    $internalHours[] = $hours;
                }
            }

            if ($row->to_status === PostStatus::Approved->value && (int) $row->approvals_before === 0) {
                $firstApprovals++;
                $cleanFirstApprovals += (int) $row->changes_before === 0 ? 1 : 0;
            }
        }

        return [$clientHours, $internalHours, $firstApprovals, $cleanFirstApprovals];
    }

    /**
     * The post that has been waiting on its client longest, right now.
     *
     * @return array{post_id: int, client_id: int, client_name: string, caption: string|null, since: string, hours_waiting: float}|null
     */
    private function oldestWaiting(AnalyticsScope $scope, CarbonImmutable $now): ?array
    {
        $since = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_id', $scope->posts()->where('status', PostStatus::WaitingClient)->select('posts.id'))
            ->where('to_status', PostStatus::WaitingClient)
            ->toBase()
            ->selectRaw('post_id, MAX(created_at) as entered_at')
            ->groupBy('post_id')
            ->orderBy('entered_at')
            ->orderBy('post_id')
            ->first();

        if ($since === null) {
            return null;
        }

        $post = Post::query()->with('client:id,name')->find((int) $since->post_id);

        if ($post === null) {
            return null;
        }

        $enteredAt = CarbonImmutable::parse((string) $since->entered_at, 'UTC');

        return [
            'post_id' => $post->id,
            'client_id' => $post->client_id,
            'client_name' => $post->client->name,
            'caption' => $post->master_caption ? Str::limit(trim($post->master_caption), 80) : null,
            'since' => $enteredAt->toIso8601String(),
            'hours_waiting' => round(max(0, $enteredAt->diffInSeconds($now)) / 3600, 1),
        ];
    }

    /**
     * Linear-interpolated percentile (the spreadsheet `PERCENTILE.INC` rule, so the median of an
     * even set is the mean of its middle pair), or null for an empty set.
     *
     * @param  list<float>  $values
     */
    public static function percentile(array $values, int $percentile): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        $position = (count($values) - 1) * max(0, min(100, $percentile)) / 100;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $value = $values[$lower] + ($values[$upper] - $values[$lower]) * ($position - $lower);

        return round($value, 1);
    }
}
