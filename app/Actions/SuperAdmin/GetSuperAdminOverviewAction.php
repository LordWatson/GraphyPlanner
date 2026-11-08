<?php

namespace App\Actions\SuperAdmin;

use App\Enums\ConnectionStatus;
use App\Models\Organization;
use App\Models\SuperAdminReport;
use App\Support\Scheduling\SchedulerHeartbeat;
use App\Support\SuperAdmin\AnalyticsFilters;
use Illuminate\Support\Carbon;

/**
 * Step 15.1/15.4 — the super admin landing page (`/super-admin`): the last 30 days of platform
 * analytics plus the lists support works from — newest signups, suspended orgs, saved reports.
 * Step I-1.8: plus system health — scheduler heartbeats (stale = no run within two intervals)
 * and the queue backlog. Audit finding 12 step 5: plus the organizations whose engagement metrics
 * have stopped arriving.
 */
class GetSuperAdminOverviewAction
{
    private const LIST_LIMIT = 5;

    public function __construct(private readonly GetPlatformAnalyticsSummaryAction $summary) {}

    /**
     * @return array{summary: array<string, mixed>, recentOrganizations: list<array<string, mixed>>, suspendedOrganizations: list<array<string, mixed>>, reports: list<array<string, mixed>>, health: array{schedulers: list<array<string, mixed>>, queue: array{pending: int|null, failed: int}, syncs: list<array<string, mixed>>}}
     */
    public function __invoke(): array
    {
        $organizationRow = fn (Organization $organization) => [
            'id' => $organization->id,
            'name' => $organization->name,
            'owner' => $organization->owner?->name,
            'owner_email' => $organization->owner?->email,
            'created_at' => $organization->created_at?->toIso8601String(),
            'suspended_at' => $organization->suspended_at?->toIso8601String(),
            'suspension_reason' => $organization->suspension_reason,
            'users_count' => (int) $organization->getAttribute('users_count'),
        ];

        return [
            'summary' => ($this->summary)(AnalyticsFilters::fromArray(['range' => '30d'])),
            'health' => [
                'schedulers' => SchedulerHeartbeat::status(),
                'queue' => SchedulerHeartbeat::queueBacklog(),
                'syncs' => $this->staleMetricSyncs(),
            ],
            'recentOrganizations' => array_values(Organization::query()
                ->excludingPreview()
                ->with('owner')
                ->withCount('users')
                ->latest()
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map($organizationRow)
                ->all()),
            'suspendedOrganizations' => array_values(Organization::query()
                ->suspended()
                ->with('owner')
                ->withCount('users')
                ->latest('suspended_at')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map($organizationRow)
                ->all()),
            'reports' => array_values(SuperAdminReport::query()
                ->latest('updated_at')
                ->limit(self::LIST_LIMIT)
                ->get()
                ->map(fn (SuperAdminReport $report) => [
                    'id' => $report->id,
                    'name' => $report->name,
                    'last_run_at' => $report->last_run_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }

    /**
     * Audit finding 12 step 5 — the organizations whose engagement metrics have stopped coming in,
     * stalest first.
     *
     * "Last read" and "last good read" are reported separately on purpose: a rate-limited or
     * rejected read still stamps `metrics_synced_at` (with the error alongside it), so an
     * organization can look synced while nothing has actually come back for days. That is the
     * silent staleness finding 12 is about, and the pair of timestamps is what makes it visible.
     *
     * Only organizations the sweep should be reading are counted — active, not the preview
     * fixture, holding a vendor key, with a connected account on a live client — so an account
     * the sweep deliberately skips never sits in here as permanent noise.
     *
     * @return list<array{org_id: int, name: string, last_read_at: string|null, last_success_at: string|null}>
     */
    private function staleMetricSyncs(): array
    {
        $staleBefore = now()->subHours(2 * max(1, (int) config('analytics.engagement.min_interval_hours', 20)));
        $success = 'MAX(CASE WHEN social_accounts.metrics_error IS NULL THEN social_accounts.metrics_synced_at END)';

        return array_values(Organization::query()
            ->excludingPreview()
            ->active()
            ->whereNotNull('organizations.upload_post_key')
            ->join('social_accounts', 'social_accounts.org_id', '=', 'organizations.id')
            ->join('clients', 'clients.id', '=', 'social_accounts.client_id')
            // A raw join sees past both models' soft-delete scopes, which the sweep itself relies on.
            ->whereNull('social_accounts.deleted_at')
            ->whereNull('clients.deleted_at')
            ->where('social_accounts.connection_status', ConnectionStatus::Connected)
            ->groupBy('organizations.id', 'organizations.name')
            ->select('organizations.id', 'organizations.name')
            ->selectRaw('MAX(social_accounts.metrics_synced_at) as last_read_at')
            ->selectRaw($success.' as last_success_at')
            ->havingRaw('last_success_at IS NULL OR last_success_at < ?', [$staleBefore])
            ->orderByRaw('CASE WHEN last_success_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('last_success_at')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Organization $organization) => [
                'org_id' => $organization->id,
                'name' => $organization->name,
                'last_read_at' => self::asIso($organization->getAttribute('last_read_at')),
                'last_success_at' => self::asIso($organization->getAttribute('last_success_at')),
            ])
            ->all());
    }

    /**
     * The aggregates above come back as raw driver values, not casts.
     */
    private static function asIso(mixed $value): ?string
    {
        return blank($value) ? null : Carbon::parse($value)->toIso8601String();
    }
}
