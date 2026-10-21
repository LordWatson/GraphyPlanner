<?php

namespace App\Actions\Analytics;

use App\Enums\ConnectionStatus;
use App\Enums\PostTargetStatus;
use App\Models\Organization;
use App\Models\PostTarget;
use App\Models\Scopes\OrganizationScope;
use App\Models\SocialAccount;
use App\Services\Analytics\AnalyticsCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Step M-3.1 / Phase 12 Step 12.1 — one organization's engagement sweep (run nightly by
 * `SyncOrganizationEngagementJob`):
 *
 * 1. every connected social account → `SyncSocialAccountMetricsAction` (followers, reach);
 * 2. every channel published in the last `analytics.engagement.window_days` → one new
 *    `post_target_metrics` row via `SyncPostTargetMetricsAction`, oldest-read first, at most
 *    `max_posts_per_org` per run (vendor rate limits) — the rest go next run.
 *
 * Anything read within `min_interval_hours` is skipped unless `$force`. Suspended organizations
 * and organizations with no Upload-Post key are skipped entirely. The org's analytics cache is
 * bumped afterwards so the new numbers show at once.
 */
class SyncOrganizationEngagementAction
{
    public function __construct(
        private readonly SyncSocialAccountMetricsAction $accounts,
        private readonly SyncPostTargetMetricsAction $targets,
    ) {}

    /**
     * @return array{accounts: array<string, int>, targets: array<string, int>, skipped: string|null}
     */
    public function __invoke(Organization $organization, bool $force = false): array
    {
        $summary = ['accounts' => ['stored' => 0, 'empty' => 0, 'failed' => 0], 'targets' => ['stored' => 0, 'empty' => 0, 'failed' => 0], 'skipped' => null];

        if ($organization->isSuspended() || blank($organization->upload_post_key)) {
            $summary['skipped'] = $organization->isSuspended() ? 'suspended' : 'no_vendor_key';
            Log::info('Engagement sync skipped', ['org_id' => $organization->id, 'reason' => $summary['skipped']]);

            return $summary;
        }

        $staleBefore = now()->subHours(max(0, (int) config('analytics.engagement.min_interval_hours', 20)));
        $fresh = fn (Builder $query) => $force ? $query : $query->where(fn (Builder $inner) => $inner
            ->whereNull('metrics_synced_at')
            ->orWhere('metrics_synced_at', '<', $staleBefore));

        Log::info('Engagement sync started', ['org_id' => $organization->id, 'force' => $force]);

        SocialAccount::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('org_id', $organization->id)
            ->where('connection_status', ConnectionStatus::Connected)
            ->whereHas('client', fn (Builder $query) => $query->whereNull('deleted_at'))
            ->tap($fresh)
            ->with('organization')
            ->orderBy('id')
            ->each(function (SocialAccount $account) use (&$summary) {
                $summary['accounts'][($this->accounts)($account)]++;
            });

        $windowStart = now()->subDays(max(1, (int) config('analytics.engagement.window_days', 90)));

        PostTarget::query()
            ->where('status', PostTargetStatus::Published)
            ->where('published_at', '>=', $windowStart)
            ->whereHas('post', fn (Builder $query) => $query->withoutGlobalScope(OrganizationScope::class)->where('org_id', $organization->id))
            ->tap($fresh)
            ->with(['socialAccount.organization'])
            ->orderByRaw('CASE WHEN metrics_synced_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('metrics_synced_at')
            ->orderBy('id')
            ->limit(max(1, (int) config('analytics.engagement.max_posts_per_org', 300)))
            ->get()
            ->each(function (PostTarget $target) use (&$summary) {
                $summary['targets'][($this->targets)($target)]++;
            });

        AnalyticsCache::bust($organization->id);

        Log::info('Engagement sync finished', ['org_id' => $organization->id, 'accounts' => $summary['accounts'], 'targets' => $summary['targets']]);

        return $summary;
    }
}
