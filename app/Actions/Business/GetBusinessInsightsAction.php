<?php

namespace App\Actions\Business;

use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\PostTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Step B-4.1 / B-8 (consistency slice) — the data behind the business "Insights" page. Until
 * engagement metrics land (Phase B-8 / Step I-10.1) this shows only what's available today:
 * publishing consistency (posts published per week, a streak, the totals), computed in the brand's
 * timezone and bounded to a fixed window of queries.
 */
class GetBusinessInsightsAction
{
    /** Weeks of history the consistency chart shows. */
    private const WEEKS = 8;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Client $brand, string $timezone): array
    {
        $now = Carbon::now($timezone);
        $weekStart = $now->clone()->startOfWeek();
        $windowStart = $weekStart->clone()->subWeeks(self::WEEKS - 1);

        $published = PostTarget::query()
            ->where('status', PostTargetStatus::Published)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $windowStart->clone()->utc())
            ->whereHas('post', fn (Builder $post) => $post->where('org_id', $brand->org_id)->where('client_id', $brand->id))
            ->with('socialAccount:id,platform')
            ->get(['id', 'social_account_id', 'published_at']);

        // Bucket published targets into local ISO weeks.
        $byWeek = array_fill(0, self::WEEKS, 0);

        foreach ($published as $target) {
            $localWeekStart = $target->published_at->clone()->setTimezone($timezone)->startOfWeek();
            $index = (int) $windowStart->diffInWeeks($localWeekStart);

            if ($index >= 0 && $index < self::WEEKS) {
                $byWeek[$index]++;
            }
        }

        $consistency = [];

        for ($i = 0; $i < self::WEEKS; $i++) {
            $start = $windowStart->clone()->addWeeks($i);
            $consistency[] = [
                'week_start' => $start->toDateString(),
                'label' => $start->isoFormat('D MMM'),
                'published' => $byWeek[$i],
            ];
        }

        $goal = $brand->postingGoal();

        return [
            'consistency' => $consistency,
            'streak' => $this->streak($byWeek),
            'publishedTotal' => (int) $published->count(),
            'byPlatform' => $published
                ->groupBy(fn (PostTarget $target) => $target->socialAccount?->platform->value ?? 'unknown')
                ->map(fn ($group, $platform) => [
                    'platform' => $platform,
                    'platform_label' => $group->first()?->socialAccount?->platform->label() ?? ucfirst((string) $platform),
                    'published' => $group->count(),
                ])
                ->values()
                ->all(),
            'goalPerWeek' => $goal->postsPerWeek,
            // No reach/engagement until Phase B-8 / Step I-10.1.
            'hasMetrics' => false,
        ];
    }

    /**
     * Consecutive most-recent weeks with at least one published post (counted back from this week).
     *
     * @param  array<int, int>  $byWeek
     */
    private function streak(array $byWeek): int
    {
        $streak = 0;

        for ($i = self::WEEKS - 1; $i >= 0; $i--) {
            if ($byWeek[$i] <= 0) {
                break;
            }

            $streak++;
        }

        return $streak;
    }
}
