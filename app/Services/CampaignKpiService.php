<?php

namespace App\Services;

use App\Enums\CampaignMetric;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignKpi;
use App\Models\PostActivityLog;
use App\Services\Analytics\ApprovalMetrics;
use App\Services\Analytics\PostActivityMetrics;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use App\Support\Campaigns\CampaignClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Step C-7.1 — a campaign's KPI actuals, computed through the shared analytics core with an
 * `AnalyticsScope` narrowed to the campaign (no new aggregation code for approvals), over the
 * campaign's whole life in the organization's timezone.
 *
 * Engagement metrics report `available: false` with "Available once engagement tracking is on."
 * and a null actual — never a zero that looks like a real result.
 */
class CampaignKpiService
{
    /**
     * A post counts as on time if it went live within this many minutes of its planned slot.
     */
    public const ON_TIME_GRACE_MINUTES = 15;

    public const UNAVAILABLE_NOTE = 'Available once engagement tracking is on.';

    public function __construct(private readonly ApprovalMetrics $approvals) {}

    public function scope(Campaign $campaign): AnalyticsScope
    {
        return new AnalyticsScope([$campaign->org_id], [$campaign->client_id], [$campaign->id]);
    }

    /**
     * From the earlier of the campaign's start and its creation, to the end of today (or of the
     * campaign's end date if that's later), in the org's timezone.
     */
    public function range(Campaign $campaign): AnalyticsRange
    {
        $timezone = CampaignClock::timezone($campaign->organization);
        $created = CarbonImmutable::parse($campaign->created_at ?? now())->setTimezone($timezone)->startOfDay();
        $start = $campaign->start_date ? CarbonImmutable::parse($campaign->start_date->toDateString(), $timezone) : $created;
        $end = CarbonImmutable::now($timezone)->endOfDay();

        return new AnalyticsRange('custom', $start->min($created), $end, $timezone);
    }

    /**
     * Every metric's actual, keyed by metric value.
     *
     * @return array<string, array{actual: float|null, available: bool, note: string|null, detail: string|null}>
     */
    public function actuals(Campaign $campaign): array
    {
        $scope = $this->scope($campaign);
        $range = $this->range($campaign);
        $approvalSummary = $this->approvals->summary($scope, $range);
        $published = $scope->posts()->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])->count();
        $onTime = $this->onTime($campaign);
        $deliverables = $this->deliverables($campaign);

        $actuals = [];

        foreach (CampaignMetric::cases() as $metric) {
            $actuals[$metric->value] = match ($metric) {
                CampaignMetric::PostsPublished => $this->value((float) $published, "{$published} live"),
                CampaignMetric::OnTimeRate => $this->value(
                    $onTime['total'] > 0 ? round($onTime['on_time'] / $onTime['total'] * 100, 1) : null,
                    $onTime['total'] > 0 ? "{$onTime['on_time']} of {$onTime['total']} on time" : 'Nothing published yet',
                ),
                CampaignMetric::ApprovalTurnaround => $this->value(
                    $approvalSummary['client_turnaround']['median_hours'],
                    $approvalSummary['client_turnaround']['decisions'] > 0
                        ? "Median of {$approvalSummary['client_turnaround']['decisions']} client decisions"
                        : 'No client decisions yet',
                ),
                CampaignMetric::FirstTimeApprovalRate => $this->value(
                    $approvalSummary['first_time_approval_rate'],
                    $approvalSummary['approved'] > 0 ? "{$approvalSummary['approved']} approvals" : 'No approvals yet',
                ),
                CampaignMetric::DeliverablesCompleted => $this->value(
                    (float) $deliverables['done'],
                    "{$deliverables['done']} of {$deliverables['total']} planned",
                ),
                default => ['actual' => null, 'available' => false, 'note' => self::UNAVAILABLE_NOTE, 'detail' => null],
            };
        }

        return $actuals;
    }

    /**
     * The campaign's KPI targets with actuals and progress, in display order.
     *
     * @return list<array<string, mixed>>
     */
    public function results(Campaign $campaign): array
    {
        $actuals = $this->actuals($campaign);

        return array_values($campaign->kpis()->get()->map(
            fn (CampaignKpi $kpi) => self::row($kpi->metric, (float) $kpi->target, $actuals[$kpi->metric->value]),
        )->all());
    }

    /**
     * @param  array{actual: float|null, available: bool, note: string|null, detail: string|null}  $actual
     * @return array<string, mixed>
     */
    public static function row(CampaignMetric $metric, float $target, array $actual): array
    {
        $value = $actual['actual'];
        $progress = null;
        $met = null;

        if ($actual['available'] && $value !== null && $target > 0) {
            $progress = $metric->lowerIsBetter()
                ? ($value <= 0 ? 1.0 : round($target / $value, 3))
                : round($value / $target, 3);
            $met = $metric->lowerIsBetter() ? $value <= $target : $value >= $target;
        }

        return [
            'metric' => $metric->value,
            'label' => $metric->label(),
            'unit' => $metric->unit(),
            'lower_is_better' => $metric->lowerIsBetter(),
            'target' => $target,
            'actual' => $value,
            'available' => $actual['available'],
            'note' => $actual['note'],
            'detail' => $actual['detail'],
            'progress' => $progress,
            'met' => $met,
        ];
    }

    /**
     * Published posts whose first "published" transition landed within the grace window of their
     * earliest planned target time. Posts with no planned time aren't counted either way.
     *
     * @return array{on_time: int, total: int}
     */
    private function onTime(Campaign $campaign): array
    {
        $planned = DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->where('posts.campaign_id', $campaign->id)
            ->whereNull('posts.deleted_at')
            ->whereIn('posts.status', [PostStatus::Published->value, PostStatus::PartiallyPublished->value])
            ->whereNotNull('post_targets.scheduled_at_utc')
            ->groupBy('posts.id')
            ->selectRaw('posts.id as post_id, min(post_targets.scheduled_at_utc) as planned_at')
            ->pluck('planned_at', 'post_id');

        if ($planned->isEmpty()) {
            return ['on_time' => 0, 'total' => 0];
        }

        $publishedAt = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_id', $planned->keys())
            ->whereIn('to_status', [PostStatus::Published, PostStatus::PartiallyPublished])
            ->toBase()
            ->groupBy('post_id')
            ->selectRaw('post_id, min(created_at) as published_at')
            ->pluck('published_at', 'post_id');

        $onTime = 0;
        $total = 0;

        foreach ($planned as $postId => $plannedAt) {
            if (! isset($publishedAt[$postId])) {
                continue;
            }

            $total++;
            $late = CarbonImmutable::parse((string) $plannedAt, 'UTC')->addMinutes(self::ON_TIME_GRACE_MINUTES);
            $onTime += CarbonImmutable::parse((string) $publishedAt[$postId], 'UTC')->lessThanOrEqualTo($late) ? 1 : 0;
        }

        return ['on_time' => $onTime, 'total' => $total];
    }

    /**
     * @return array{done: int, total: int}
     */
    private function deliverables(Campaign $campaign): array
    {
        $row = DB::table('campaign_deliverables')
            ->leftJoin('posts', function ($join) {
                $join->on('posts.id', '=', 'campaign_deliverables.post_id')->whereNull('posts.deleted_at');
            })
            ->where('campaign_deliverables.campaign_id', $campaign->id)
            ->selectRaw('count(*) as total, sum(case when posts.status in (?, ?) then 1 else 0 end) as done', [
                PostStatus::Published->value,
                PostStatus::PartiallyPublished->value,
            ])
            ->first();

        return ['done' => (int) ($row->done ?? 0), 'total' => (int) ($row->total ?? 0)];
    }

    /**
     * @return array{actual: float|null, available: bool, note: string|null, detail: string|null}
     */
    private function value(?float $actual, ?string $detail): array
    {
        return ['actual' => $actual, 'available' => true, 'note' => null, 'detail' => $detail];
    }
}
