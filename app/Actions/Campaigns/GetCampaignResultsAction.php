<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignMetric;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignPhase;
use App\Models\Post;
use App\Models\PostActivityLog;
use App\Models\User;
use App\Services\Analytics\ApprovalMetrics;
use App\Services\Analytics\EngagementMetrics;
use App\Services\Analytics\PostActivityMetrics;
use App\Services\CampaignKpiService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Step C-7.2 — the Results tab: KPI targets vs actuals, published-over-time by phase, the platform
 * split, top posts (by engagement — M-3.1 — else approval speed), approval turnaround, and
 * the wrap report (C-7.3). Never carries fees or invoices — billing lives on Overview, for
 * Owner/Strategist only.
 */
class GetCampaignResultsAction
{
    public const TOP_POSTS = 5;

    public function __construct(
        private readonly CampaignKpiService $kpis,
        private readonly ApprovalMetrics $approvals,
        private readonly EngagementMetrics $engagement,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Campaign $campaign, User $user): array
    {
        $scope = $this->kpis->scope($campaign);
        $range = $this->kpis->range($campaign);
        $timezone = $range->timezone;
        $actuals = $this->kpis->actuals($campaign);

        $published = $this->published($campaign);
        $topPosts = $this->topPosts($campaign);

        return [
            'kpis' => $this->kpis->results($campaign, $actuals),
            'metrics' => array_map(fn (CampaignMetric $metric) => [
                'value' => $metric->value,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'available' => $actuals[$metric->value]['available'],
                'actual' => $actuals[$metric->value]['actual'],
                'detail' => $actuals[$metric->value]['detail'],
                'note' => $actuals[$metric->value]['note'],
            ], CampaignMetric::cases()),
            'series' => $this->series($published, $campaign, $timezone),
            'platforms' => $this->platforms($campaign),
            'topPosts' => $topPosts,
            'topPostsRankedBy' => $topPosts[0]['ranked_by'] ?? 'engagement',
            'approvals' => $this->approvals->summary($scope, $range),
            'report' => $this->report($campaign),
            'canEditResults' => $user->can('update', $campaign),
        ];
    }

    /**
     * When each published post first went live, with the phase its deliverable sits in.
     *
     * @return list<array{post_id: int, at: string, phase_id: int|null}>
     */
    private function published(Campaign $campaign): array
    {
        $postIds = $campaign->posts()->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])->pluck('id');

        if ($postIds->isEmpty()) {
            return [];
        }

        $times = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_id', $postIds)
            ->whereIn('to_status', [PostStatus::Published, PostStatus::PartiallyPublished])
            ->toBase()
            ->groupBy('post_id')
            ->selectRaw('post_id, min(created_at) as published_at')
            ->pluck('published_at', 'post_id');

        $phases = $campaign->deliverables()->whereIn('post_id', $postIds)->pluck('campaign_phase_id', 'post_id');

        $rows = [];

        foreach ($times as $postId => $at) {
            $rows[] = ['post_id' => (int) $postId, 'at' => (string) $at, 'phase_id' => isset($phases[$postId]) ? (int) $phases[$postId] : null];
        }

        return $rows;
    }

    /**
     * Daily buckets (weekly when the campaign spans more than ~9 weeks) from the campaign's start
     * to today or its end, each split by phase id (`0` = unphased).
     *
     * @param  list<array{post_id: int, at: string, phase_id: int|null}>  $published
     * @return array{granularity: string, buckets: list<array<string, mixed>>, phases: list<array{id: int, name: string, colour: string|null}>}
     */
    private function series(array $published, Campaign $campaign, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $campaign->start_date ? CarbonImmutable::parse($campaign->start_date->toDateString(), $timezone) : null;
        $firstPublished = collect($published)->map(fn ($row) => CarbonImmutable::parse($row['at'], 'UTC')->setTimezone($timezone)->startOfDay())->min();
        $start = collect([$start, $firstPublished])->filter()->min() ?? $today;
        $end = $campaign->end_date ? CarbonImmutable::parse($campaign->end_date->toDateString(), $timezone) : $today;
        $end = $end->max($start);

        $weekly = $start->diffInDays($end) > 62;
        $cursor = $weekly ? $start->startOfWeek() : $start;
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $buckets[$cursor->toDateString()] = ['date' => $cursor->toDateString(), 'total' => 0, 'by_phase' => []];
            $cursor = $weekly ? $cursor->addWeek() : $cursor->addDay();
        }

        foreach ($published as $row) {
            $day = CarbonImmutable::parse($row['at'], 'UTC')->setTimezone($timezone)->startOfDay();
            $key = ($weekly ? $day->startOfWeek() : $day)->toDateString();

            if (! isset($buckets[$key])) {
                continue;
            }

            $phase = (string) ($row['phase_id'] ?? 0);
            $buckets[$key]['total']++;
            $buckets[$key]['by_phase'][$phase] = ($buckets[$key]['by_phase'][$phase] ?? 0) + 1;
        }

        return [
            'granularity' => $weekly ? 'week' : 'day',
            'buckets' => array_values($buckets),
            'phases' => array_values($campaign->phases()->get()->map(fn (CampaignPhase $phase) => [
                'id' => $phase->id,
                'name' => $phase->name,
                'colour' => $phase->colour?->value,
            ])->all()),
        ];
    }

    /**
     * Published posts per platform (a post with two targets counts once per platform).
     *
     * @return list<array{platform: string, published: int, scheduled: int}>
     */
    private function platforms(Campaign $campaign): array
    {
        return array_values(DB::table('post_targets')
            ->join('posts', 'posts.id', '=', 'post_targets.post_id')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_targets.social_account_id')
            ->where('posts.campaign_id', $campaign->id)
            ->whereNull('posts.deleted_at')
            ->groupBy('social_accounts.platform')
            ->selectRaw('social_accounts.platform as platform')
            ->selectRaw('count(distinct case when posts.status in (?, ?) then posts.id end) as published', [PostStatus::Published->value, PostStatus::PartiallyPublished->value])
            ->selectRaw('count(distinct case when posts.status in (?, ?) then posts.id end) as scheduled', [PostStatus::Scheduled->value, PostStatus::Approved->value])
            ->get()
            ->map(fn ($row) => ['platform' => (string) $row->platform, 'published' => (int) $row->published, 'scheduled' => (int) $row->scheduled])
            ->all());
    }

    /**
     * Step M-3.1: the campaign's posts with the most interactions (each post's channels summed,
     * latest reading each). Before any reading exists, "top" falls back to the posts the client
     * signed off fastest — each row says which via `ranked_by`.
     *
     * @return list<array<string, mixed>>
     */
    public function topPosts(Campaign $campaign): array
    {
        $engaged = $this->engagement->topPosts(
            $this->engagement->channels($this->kpis->scope($campaign), $this->kpis->range($campaign)),
            self::TOP_POSTS,
        );

        if ($engaged !== []) {
            $statuses = Post::query()->whereIn('id', array_column($engaged, 'post_id'))->get(['id', 'status'])->keyBy('id');

            return array_map(fn (array $row) => [
                'id' => $row['post_id'],
                'caption' => $row['caption'],
                'status' => $statuses->get($row['post_id'])?->status->value,
                'status_label' => $statuses->get($row['post_id'])?->status->label(),
                'platforms' => $row['platforms'],
                'thumbnail_url' => $row['thumbnail_url'],
                'post_url' => $row['post_url'],
                'engagement' => $row['engagement'],
                'reach' => $row['reach'],
                'impressions' => $row['impressions'],
                'engagement_rate' => $row['engagement_rate'],
                'hours_to_approve' => null,
                'ranked_by' => 'engagement',
            ], $engaged);
        }

        return $this->fastestApproved($campaign);
    }

    /**
     * Before engagement readings exist: the posts the client signed off fastest.
     *
     * @return list<array<string, mixed>>
     */
    private function fastestApproved(Campaign $campaign): array
    {
        $logs = PostActivityMetrics::realTransitions(PostActivityLog::query())
            ->whereIn('post_id', $campaign->posts()->select('id'))
            ->whereIn('to_status', [PostStatus::WaitingClient, PostStatus::Approved])
            ->orderBy('post_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['post_id', 'to_status', 'created_at']);

        $speeds = [];

        foreach ($logs->groupBy('post_id') as $postId => $rows) {
            $sentAt = null;

            foreach ($rows as $row) {
                if ($row->to_status === PostStatus::WaitingClient) {
                    $sentAt = $row->created_at;
                } elseif ($row->to_status === PostStatus::Approved && $sentAt !== null) {
                    $speeds[(int) $postId] = round(max(0, $sentAt->diffInSeconds($row->created_at)) / 3600, 1);

                    break;
                }
            }
        }

        asort($speeds);
        $fastest = array_slice($speeds, 0, self::TOP_POSTS, true);
        $posts = Post::query()->whereIn('id', array_keys($fastest))->with('targets.socialAccount')->get()->keyBy('id');

        $rows = [];

        foreach ($fastest as $postId => $hours) {
            $post = $posts->get($postId);

            if ($post === null) {
                continue;
            }

            $rows[] = [
                'id' => $post->id,
                'caption' => $post->master_caption ? Str::limit(trim($post->master_caption), 90) : null,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'platforms' => $post->targets->map(fn ($target) => $target->socialAccount?->platform->value)->filter()->unique()->values()->all(),
                'thumbnail_url' => null,
                'post_url' => null,
                'engagement' => null,
                'reach' => null,
                'impressions' => null,
                'engagement_rate' => null,
                'hours_to_approve' => $hours,
                'ranked_by' => 'approval_speed',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function report(Campaign $campaign): ?array
    {
        $report = $campaign->report;

        if ($report === null) {
            return null;
        }

        return [
            'summary' => $report->summary,
            'highlights' => $report->highlights ?? [],
            'learnings' => $report->learnings,
            'next_steps' => $report->next_steps,
            'status' => $report->status->value,
            'version' => $report->version,
            'shared_at' => $report->shared_at?->toIso8601String(),
            'shared_by' => $report->sharer?->name,
            'kpi_snapshot' => $report->kpi_snapshot,
            'engagement_snapshot' => $report->engagement_snapshot,
        ];
    }
}
