<?php

namespace App\Actions\Analytics;

use App\Enums\CampaignReportStatus;
use App\Enums\Platform;
use App\Models\CampaignReport;
use App\Models\Client;
use App\Models\Scopes\OrganizationScope;
use App\Services\Analytics\ApprovalMetrics;
use App\Services\Analytics\EngagementMetrics;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Step M-3.3 / I-10.3 (Phase 8 on Phase 17's services) — the figures of one client's monthly
 * report, built only from the shared analytics layer (no second aggregation layer):
 *
 * - posts shipped (published channels by platform), engagement and follower growth
 *   (`EngagementMetrics`), the top posts, approvals turnaround (`ApprovalMetrics` — the client's
 *   own decisions only; internal review stages are left out), posts waiting on the client right
 *   now, and the campaign wrap reports shared that month (C-7.3 note).
 *
 * **Client-safe by construction:** one client's scope, no billing figures of any kind, only work
 * that reached the client (published posts; waiting-on-client counts), no internal notes, no
 * staff names, no thumbnails (signed URLs expire — the page adds fresh ones by post id).
 */
class BuildClientReportSnapshotAction
{
    public function __construct(
        private readonly EngagementMetrics $engagement,
        private readonly ApprovalMetrics $approvals,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Client $client, CarbonImmutable $periodStart): array
    {
        $organization = $client->organization;
        $timezone = in_array($organization->default_timezone, timezone_identifiers_list(), true) ? $organization->default_timezone : 'UTC';
        $from = CarbonImmutable::parse($periodStart->toDateString(), $timezone)->startOfMonth();
        $to = $from->endOfMonth();
        $range = new AnalyticsRange('custom', $from, $to, $timezone);
        $scope = new AnalyticsScope([$client->org_id], [$client->id]);

        $engagement = $this->engagement->summary($scope, $range, topPosts: 5);
        $approvals = $this->approvals->summary($scope, $range);
        $channels = $this->engagement->channels($scope, $range);

        $byPlatform = [];

        foreach ($channels->groupBy('platform') as $platform => $rows) {
            $byPlatform[] = [
                'platform' => (string) $platform,
                'label' => Platform::tryFrom((string) $platform)?->label() ?? (string) $platform,
                'published' => $rows->count(),
            ];
        }

        usort($byPlatform, fn (array $a, array $b) => [$b['published'], $a['label']] <=> [$a['published'], $b['label']]);

        return [
            'version' => 1,
            'period' => [
                'start' => $from->toDateString(),
                'end' => $to->toDateString(),
                'label' => $from->format('F Y'),
                'timezone' => $timezone,
            ],
            'client' => ['name' => $client->name],
            'studio' => ['name' => $organization->name],
            'posts' => [
                'published' => $channels->pluck('post_id')->unique()->count(),
                'channels' => $channels->count(),
                'by_platform' => $byPlatform,
                'waiting_on_client' => $approvals['waiting_now'],
            ],
            'engagement' => [
                'measured_channels' => $engagement['measured_channels'],
                'totals' => $engagement['totals'],
                'engagement_rate' => $engagement['engagement_rate'],
                'by_platform' => array_map(fn (array $row) => array_diff_key($row, ['impressions' => true]), $engagement['by_platform']),
                'followers' => [
                    'current' => $engagement['followers']['current'],
                    'growth' => $engagement['followers']['growth'],
                    'accounts' => array_map(fn (array $account) => array_diff_key($account, ['client_id' => true]), $engagement['followers']['accounts']),
                ],
                'series' => $engagement['series'],
            ],
            'top_posts' => array_map(fn (array $post) => [
                'post_id' => $post['post_id'],
                'caption' => $post['caption'],
                'platforms' => $post['platforms'],
                'published_at' => $post['published_at'],
                'post_url' => $post['post_url'],
                'engagement' => $post['engagement'],
                'reach' => $post['reach'],
                'impressions' => $post['impressions'],
                'likes' => $post['likes'],
                'comments' => $post['comments'],
                'shares' => $post['shares'],
                'saves' => $post['saves'],
                'engagement_rate' => $post['engagement_rate'],
            ], $engagement['top_posts']),
            'approvals' => [
                'sent_to_client' => $approvals['sent_to_client'],
                'approved' => $approvals['approved'],
                'changes_requested' => $approvals['changes_requested'],
                'first_time_approval_rate' => $approvals['first_time_approval_rate'],
                'median_hours' => $approvals['client_turnaround']['median_hours'],
                'decisions' => $approvals['client_turnaround']['decisions'],
            ],
            'campaigns' => $this->wrapReports($client, $range),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Campaign wrap reports shared with the client during the month (C-7.3 note).
     *
     * @return list<array{campaign_id: int, name: string, shared_at: string|null, summary: string|null}>
     */
    private function wrapReports(Client $client, AnalyticsRange $range): array
    {
        return array_values(CampaignReport::query()
            ->where('status', CampaignReportStatus::Shared)
            ->whereBetween('shared_at', $range->utcBounds())
            ->whereHas('campaign', fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)->where('client_id', $client->id))
            ->with(['campaign' => fn ($query) => $query->withoutGlobalScope(OrganizationScope::class)])
            ->orderBy('shared_at')
            ->get()
            ->map(fn (CampaignReport $report) => [
                'campaign_id' => $report->campaign_id,
                'name' => (string) $report->campaign?->name,
                'shared_at' => $report->shared_at?->toIso8601String(),
                'summary' => $report->summary === null ? null : Str::limit(trim($report->summary), 280),
            ])
            ->all());
    }
}
