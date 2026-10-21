<?php

namespace App\Services\Analytics;

use App\Enums\PostTargetStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Step M-3.2 / I-10.2 — best time to post, per social account, from real engagement: every channel
 * the account published in the last `analytics.best_time.lookback_days` that has a reading is
 * placed in a slot (ISO weekday + a `block_hours` window, in the **account's** timezone — the
 * dual-timezone rule), and slots are ranked by average engagement rate (interactions ÷ reach,
 * else impressions, else views). When too few readings carry an audience figure, slots rank by
 * average interactions instead, and the result says which.
 *
 * Replaces the heuristic planned in AI Step 2.10. An account with fewer than `min_account_posts`
 * measured posts is "learning" and gets no slots — a guess dressed up as data would be worse
 * than none. Consumed by the post editor's schedule rows (one click picks the next such slot) and
 * the analytics engagement section; the queue (I-3.7) will read the same output when it lands.
 */
class BestTimeService
{
    private const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /**
     * @param  list<int>  $accountIds
     * @return array<int, array{status: 'ready'|'learning', sample: int, needed: int, ranked_by: 'rate'|'interactions'|null, slots: list<array{weekday: int, hour: int, block_hours: int, label: string, posts: int, score: float}>}>
     */
    public function forAccounts(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = DB::table('post_targets')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->join('post_target_metrics as m', 'm.id', '=', 'post_targets.latest_metrics_id')
            ->whereIn('post_targets.social_account_id', $accountIds)
            ->where('post_targets.status', PostTargetStatus::Published->value)
            ->where('post_targets.published_at', '>=', now()->subDays(max(1, (int) config('analytics.best_time.lookback_days', 180))))
            ->get([
                'post_targets.social_account_id',
                'post_targets.published_at',
                'social_accounts.timezone',
                'm.engagement',
                'm.reach',
                'm.impressions',
                'm.views',
            ])
            ->groupBy('social_account_id');

        $result = [];

        foreach ($accountIds as $accountId) {
            $result[$accountId] = $this->forRows($rows->get($accountId, collect()));
        }

        return $result;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array{status: 'ready'|'learning', sample: int, needed: int, ranked_by: 'rate'|'interactions'|null, slots: list<array{weekday: int, hour: int, block_hours: int, label: string, posts: int, score: float}>}
     */
    private function forRows(Collection $rows): array
    {
        $needed = max(1, (int) config('analytics.best_time.min_account_posts', 6));
        $minPosts = max(1, (int) config('analytics.best_time.min_posts', 2));
        $block = max(1, min(24, (int) config('analytics.best_time.block_hours', 2)));
        $limit = max(1, (int) config('analytics.best_time.slots', 3));

        $measured = $rows->filter(fn ($row) => $row->engagement !== null);
        $withRate = $measured->filter(fn ($row) => ($row->reach ?? $row->impressions ?? $row->views) > 0);
        $byRate = $withRate->count() >= $needed;
        $sample = $byRate ? $withRate : $measured;

        if ($sample->count() < $needed) {
            return ['status' => 'learning', 'sample' => $measured->count(), 'needed' => $needed, 'ranked_by' => null, 'slots' => []];
        }

        $slots = [];

        foreach ($sample as $row) {
            $timezone = in_array($row->timezone, timezone_identifiers_list(), true) ? (string) $row->timezone : 'UTC';
            $local = CarbonImmutable::parse((string) $row->published_at, 'UTC')->setTimezone($timezone);
            $hour = intdiv($local->hour, $block) * $block;
            $key = $local->dayOfWeekIso.'-'.$hour;
            $score = $byRate
                ? (int) $row->engagement / (int) ($row->reach ?? $row->impressions ?? $row->views) * 100
                : (float) $row->engagement;

            $slots[$key] ??= ['weekday' => $local->dayOfWeekIso, 'hour' => $hour, 'scores' => []];
            $slots[$key]['scores'][] = $score;
        }

        $ranked = collect($slots)
            ->filter(fn (array $slot) => count($slot['scores']) >= $minPosts)
            ->map(fn (array $slot) => [
                'weekday' => $slot['weekday'],
                'hour' => $slot['hour'],
                'block_hours' => $block,
                'label' => sprintf('%s %02d:00–%02d:00', self::WEEKDAYS[$slot['weekday']], $slot['hour'], min(24, $slot['hour'] + $block)),
                'posts' => count($slot['scores']),
                'score' => round(array_sum($slot['scores']) / count($slot['scores']), 2),
            ])
            ->sortBy([['score', 'desc'], ['posts', 'desc'], ['weekday', 'asc'], ['hour', 'asc']])
            ->take($limit)
            ->values()
            ->all();

        return [
            'status' => 'ready',
            'sample' => $sample->count(),
            'needed' => $needed,
            'ranked_by' => $byRate ? 'rate' : 'interactions',
            'slots' => array_values($ranked),
        ];
    }
}
