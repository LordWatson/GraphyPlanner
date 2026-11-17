<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\PublishFailureReason;
use App\Enums\StoryHandoffOutcome;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\PostTarget;
use App\Models\StoryHandoff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GetPublishReliabilityStatsAction
{
    /**
     * How long after a handoff is closed as missed its post's failure transition can be logged.
     * Both are written in one transaction (`ExpireStoryHandoffAction`), so this is only slack.
     */
    private const MISSED_HANDOFF_SLACK_SECONDS = 60;

    /**
     * Build the last `$months` months of publish failure vs. success counts for an
     * organization, along with the average number of failed attempts it took before each
     * post that eventually published successfully got there, for use in the dashboard
     * "publish reliability" chart.
     *
     * Step S-5.3: a Story nobody posted by hand in time is not a publishing failure — the vendor
     * was never asked. A failure transition written when one of the post's handoffs closed as
     * `missed` is counted as `missed_by_hand` instead of `failed`, and is not an "attempt" in the
     * average either. It stays a `failed` when another channel of that post is failed for a reason
     * of its own (the vendor did fail), so a real failure is never hidden behind a missed Story.
     *
     * Grouping is done in PHP (rather than a driver-specific SQL date function) so the
     * query behaves the same on MySQL (production) and SQLite (tests).
     *
     * @return array{
     *     monthly: array<int, array{month: string, label: string, failed: int, missed_by_hand: int, published: int}>,
     *     averageAttemptsToSuccess: float|null,
     *     missedByHand: int,
     * }
     */
    public function __invoke(int $orgId, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);
        $orgPosts = fn () => Post::query()->where('org_id', $orgId)->select('id');

        $logs = PostActivityLog::query()
            // Step I-1.3: `partially_published` counts as a failed attempt.
            ->whereIn('to_status', [PostStatus::Failed, PostStatus::PartiallyPublished, PostStatus::Published])
            ->where('created_at', '>=', $start)
            ->whereIn('post_id', $orgPosts())
            ->orderBy('created_at')
            ->get(['id', 'post_id', 'to_status', 'created_at']);

        $missedLogIds = $this->missedByHandLogIds($logs, $orgPosts(), $start);
        $isFailure = fn (PostActivityLog $log) => $log->to_status !== PostStatus::Published;
        $isMissed = fn (PostActivityLog $log) => isset($missedLogIds[$log->id]);

        $monthly = [];

        for ($i = 0; $i < $months; $i++) {
            $cursor = $start->copy()->addMonths($i);
            $key = $cursor->format('Y-m');

            $monthLogs = $logs->filter(
                fn (PostActivityLog $log) => $log->created_at?->format('Y-m') === $key
            );

            $monthly[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'failed' => $monthLogs->filter(fn (PostActivityLog $log) => $isFailure($log) && ! $isMissed($log))->count(),
                'missed_by_hand' => $monthLogs->filter($isMissed)->count(),
                'published' => $monthLogs->where('to_status', PostStatus::Published)->count(),
            ];
        }

        $attemptCounts = [];

        foreach ($logs->groupBy('post_id') as $postLogs) {
            $failuresSoFar = 0;

            foreach ($postLogs as $log) {
                if ($isMissed($log)) {
                    continue;
                }

                if ($isFailure($log)) {
                    $failuresSoFar++;

                    continue;
                }

                $attemptCounts[] = $failuresSoFar + 1;

                break;
            }
        }

        return [
            'monthly' => $monthly,
            'averageAttemptsToSuccess' => count($attemptCounts) > 0
                ? round(array_sum($attemptCounts) / count($attemptCounts), 2)
                : null,
            'missedByHand' => count($missedLogIds),
        ];
    }

    /**
     * The failure transitions that were a missed handoff and nothing else.
     *
     * @param  Collection<int, PostActivityLog>  $logs
     * @param  Builder<Post>  $orgPosts
     * @return array<int, true> keyed by activity log id
     */
    private function missedByHandLogIds(Collection $logs, Builder $orgPosts, Carbon $start): array
    {
        $missed = StoryHandoff::query()
            ->where('outcome', StoryHandoffOutcome::Missed)
            ->where('completed_at', '>=', $start)
            ->whereIn('post_id', $orgPosts)
            ->get(['id', 'post_id', 'post_target_id', 'completed_at'])
            ->groupBy('post_id');

        if ($missed->isEmpty()) {
            return [];
        }

        // Posts where a channel is failed for its own reason: the vendor did fail there.
        $vendorFailedPostIds = PostTarget::query()
            ->whereIn('post_id', $missed->keys())
            ->where('status', PostTargetStatus::Failed)
            ->get(['id', 'post_id', 'error'])
            ->reject(fn (PostTarget $target) => PublishFailureReason::classify($target->error) === PublishFailureReason::StoryNotPosted)
            ->pluck('post_id')
            ->flip();

        $ids = [];

        foreach ($logs as $log) {
            if ($log->to_status === PostStatus::Published || $log->created_at === null || $vendorFailedPostIds->has($log->post_id)) {
                continue;
            }

            foreach ($missed->get($log->post_id, []) as $handoff) {
                $seconds = $handoff->completed_at?->diffInSeconds($log->created_at, false);

                if ($seconds !== null && $seconds >= 0 && $seconds <= self::MISSED_HANDOFF_SLACK_SECONDS) {
                    $ids[$log->id] = true;

                    break;
                }
            }
        }

        return $ids;
    }
}
