<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostActivityLog;
use Illuminate\Support\Carbon;

class GetPublishReliabilityStatsAction
{
    /**
     * Build the last `$months` months of publish failure vs. success counts for an
     * organization, along with the average number of failed attempts it took before each
     * post that eventually published successfully got there, for use in the dashboard
     * "publish reliability" chart.
     *
     * Grouping is done in PHP (rather than a driver-specific SQL date function) so the
     * query behaves the same on Postgres (production) and SQLite (tests).
     *
     * @return array{
     *     monthly: array<int, array{month: string, label: string, failed: int, published: int}>,
     *     averageAttemptsToSuccess: float|null,
     * }
     */
    public function __invoke(int $orgId, int $months = 6): array
    {
        $start = Carbon::now()->startOfMonth()->subMonths($months - 1);

        $logs = PostActivityLog::query()
            ->whereIn('to_status', [PostStatus::Failed, PostStatus::Published])
            ->where('created_at', '>=', $start)
            ->whereIn('post_id', Post::query()->where('org_id', $orgId)->select('id'))
            ->orderBy('created_at')
            ->get(['post_id', 'to_status', 'created_at']);

        $monthly = [];

        for ($i = 0; $i < $months; $i++) {
            $cursor = $start->copy()->addMonths($i);
            $key = $cursor->format('Y-m');

            $monthLogs = $logs->filter(
                fn (PostActivityLog $log) => $log->created_at->format('Y-m') === $key
            );

            $monthly[] = [
                'month' => $key,
                'label' => $cursor->format('M Y'),
                'failed' => $monthLogs->where('to_status', PostStatus::Failed)->count(),
                'published' => $monthLogs->where('to_status', PostStatus::Published)->count(),
            ];
        }

        $attemptCounts = [];

        foreach ($logs->groupBy('post_id') as $postLogs) {
            $failuresSoFar = 0;

            foreach ($postLogs as $log) {
                if ($log->to_status === PostStatus::Failed) {
                    $failuresSoFar++;

                    continue;
                }

                if ($log->to_status === PostStatus::Published) {
                    $attemptCounts[] = $failuresSoFar + 1;

                    break;
                }
            }
        }

        return [
            'monthly' => $monthly,
            'averageAttemptsToSuccess' => count($attemptCounts) > 0
                ? round(array_sum($attemptCounts) / count($attemptCounts), 2)
                : null,
        ];
    }
}
