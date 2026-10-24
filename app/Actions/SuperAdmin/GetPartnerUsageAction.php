<?php

namespace App\Actions\SuperAdmin;

use App\Enums\PostStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Analytics\AiUsageMetrics;
use App\Services\Analytics\ApprovalMetrics;
use App\Services\Analytics\PostActivityMetrics;
use App\Services\Analytics\RevenueMetrics;
use App\Support\Analytics\AnalyticsRange;
use App\Support\Analytics\AnalyticsScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Step M-8.2 — the design-partner usage view: one row per `is_design_partner` organization with the
 * numbers that say whether they're really using Alima — weekly active users, posts published,
 * approvals, invoices sent and AI calls — over the selected range (weekly-active users are always a
 * fixed 7-day window, since that's what "weekly" means).
 *
 * Every figure reuses the Phase 15 / Phase 17 analytics core (`PostActivityMetrics`,
 * `RevenueMetrics`, `AiUsageMetrics`) scoped to one org, so the partner view can never drift from
 * the platform analytics. There are at most five partners, so a per-org pass is cheap; WAU is a
 * single grouped query across all of them.
 */
class GetPartnerUsageAction
{
    private const WEEKLY_ACTIVE_DAYS = 7;

    public function __construct(
        private readonly PostActivityMetrics $postMetrics,
        private readonly ApprovalMetrics $approvalMetrics,
        private readonly RevenueMetrics $revenueMetrics,
        private readonly AiUsageMetrics $aiMetrics,
    ) {}

    /**
     * @return array{range: array{range: string, from: string, to: string, granularity: string, timezone: string}, weekly_active_since: string, partners: list<array<string, mixed>>, totals: array{partners: int, weekly_active_users: int, posts_published: int, approvals: int, client_approvals: int, internal_approvals: int, invoices_sent: int, ai_calls: int}}
     */
    public function __invoke(AnalyticsRange $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $weeklyActiveSince = $now->subDays(self::WEEKLY_ACTIVE_DAYS);

        $partners = Organization::query()
            ->designPartners()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $partnerIds = array_values(array_map(static fn ($id): int => (int) $id, $partners->pluck('id')->all()));
        $weeklyActive = $this->weeklyActiveByOrg($partnerIds, $weeklyActiveSince);

        $rows = $partners->map(function (Organization $partner) use ($range, $weeklyActive): array {
            $scope = AnalyticsScope::organizations([$partner->id]);
            $approvals = $this->approvalMetrics->approvalsBySource($scope, $range);

            return [
                'id' => $partner->id,
                'name' => $partner->name,
                'slug' => $partner->slug,
                'created_at' => $partner->created_at?->toIso8601String(),
                'is_suspended' => $partner->isSuspended(),
                'members' => (int) $partner->getAttribute('users_count'),
                'weekly_active_users' => $weeklyActive[$partner->id] ?? 0,
                'posts_published' => $this->postMetrics->transitionsInRange($scope, $range, PostStatus::Published)->count(),
                // Approvals are split by who signed off (client vs internal), counted from the
                // decision record. `approvals` stays the total for the headline figure.
                'approvals' => $approvals['total'],
                'client_approvals' => $approvals['client'],
                'internal_approvals' => $approvals['internal'],
                'invoices_sent' => $this->revenueMetrics->issued($scope, $range)->count(),
                'ai_calls' => $this->aiMetrics->calls($scope, $range)->count(),
            ];
        })->values();

        $totals = [
            'partners' => $rows->count(),
            'weekly_active_users' => (int) $rows->sum('weekly_active_users'),
            'posts_published' => (int) $rows->sum('posts_published'),
            'approvals' => (int) $rows->sum('approvals'),
            'client_approvals' => (int) $rows->sum('client_approvals'),
            'internal_approvals' => (int) $rows->sum('internal_approvals'),
            'invoices_sent' => (int) $rows->sum('invoices_sent'),
            'ai_calls' => (int) $rows->sum('ai_calls'),
        ];

        Log::info('Partner usage view built', [
            'range' => $range->preset,
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'partners' => $totals['partners'],
        ]);

        return [
            'range' => $range->toArray(),
            'weekly_active_since' => $weeklyActiveSince->toIso8601String(),
            'partners' => array_values($rows->all()),
            'totals' => $totals,
        ];
    }

    /**
     * Distinct active users per org in the weekly window — one grouped query (one `users` row per
     * user, so a count is the distinct count).
     *
     * @param  list<int>  $orgIds
     * @return array<int, int>
     */
    private function weeklyActiveByOrg(array $orgIds, CarbonImmutable $since): array
    {
        if ($orgIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('org_id', $orgIds)
            ->where('last_active_at', '>=', $since)
            ->toBase()
            ->selectRaw('org_id, COUNT(*) as aggregate')
            ->groupBy('org_id')
            ->pluck('aggregate', 'org_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }
}
