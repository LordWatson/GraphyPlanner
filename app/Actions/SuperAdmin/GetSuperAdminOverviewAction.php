<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\SuperAdminReport;
use App\Support\SuperAdmin\AnalyticsFilters;

/**
 * Step 15.1/15.4 — the super admin landing page (`/super-admin`): the last 30 days of platform
 * analytics plus the lists support works from — newest signups, suspended orgs, saved reports.
 */
class GetSuperAdminOverviewAction
{
    private const LIST_LIMIT = 5;

    public function __construct(private readonly GetPlatformAnalyticsSummaryAction $summary) {}

    /**
     * @return array{summary: array<string, mixed>, recentOrganizations: list<array<string, mixed>>, suspendedOrganizations: list<array<string, mixed>>, reports: list<array<string, mixed>>}
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
}
