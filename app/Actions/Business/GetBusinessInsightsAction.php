<?php

namespace App\Actions\Business;

use App\Enums\PostTargetStatus;
use App\Models\Client;
use App\Models\PostTarget;
use App\Services\Analytics\EngagementMetrics;
use App\Services\Business\BestTimeService;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Step B-4.1 / B-8.1 — the data behind the business "Insights" page: a single brand's own numbers,
 * built on the shared analytics core (Phase 17) scoped to the primary brand. It never shows
 * revenue, client-health or staff-cost sections — only what a solo owner/creator cares about:
 *
 * - **Consistency** (always available): posts published per ISO week over the last 8 weeks, the
 *   current streak, and the weekly goal — computed in the brand's timezone.
 * - **Reach & engagement** (when engagement metrics have landed, Step I-10.1): totals, engagement
 *   rate, by-platform split, top posts and follower growth, read through {@see EngagementMetrics}
 *   over the engagement window so the same numbers back Insights as back the agency analytics page.
 * - **Best times to post** ({@see BestTimeService}): per connected account, learned from the brand's
 *   own history or a sensible platform default.
 *
 * `hasMetrics` is true only once at least one published channel has a reading, so the page can show
 * encouraging empty states until the data arrives rather than a wall of zeros.
 */
class GetBusinessInsightsAction
{
    /** Weeks of history the consistency chart shows. */
    private const WEEKS = 8;

    /** Days of engagement history the reach/top-posts/followers sections cover. */
    private const ENGAGEMENT_DAYS = 90;

    public function __construct(
        private readonly EngagementMetrics $engagement,
        private readonly BestTimeService $bestTimes,
    ) {}

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
        $engagement = $this->engagementSummary($brand, $timezone);

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
            'engagement' => $engagement,
            'bestTimes' => $this->bestTimes->forBrand($brand),
            'windowDays' => self::ENGAGEMENT_DAYS,
            'hasMetrics' => $engagement['measured_channels'] > 0,
        ];
    }

    /**
     * The reach/engagement slice from the shared read layer, scoped to this one brand over the
     * engagement window and the brand's timezone. Follower growth is kept; best times come from the
     * business service instead (it adds the platform-default fallback), so we drop the learned-only
     * `best_times` the core would attach.
     *
     * @return array<string, mixed>
     */
    private function engagementSummary(Client $brand, string $timezone): array
    {
        $scope = new AnalyticsScope(orgIds: [$brand->org_id], clientIds: [$brand->id]);
        $range = AnalyticsRange::fromArray(['range' => 'custom'] + $this->engagementBounds($timezone), $timezone);

        $summary = $this->engagement->summary($scope, $range, withBestTimes: false);
        unset($summary['best_times']);

        return $summary;
    }

    /**
     * @return array{from: string, to: string}
     */
    private function engagementBounds(string $timezone): array
    {
        $end = Carbon::now($timezone);

        return [
            'from' => $end->clone()->subDays(self::ENGAGEMENT_DAYS - 1)->toDateString(),
            'to' => $end->toDateString(),
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
