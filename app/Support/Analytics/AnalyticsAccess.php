<?php

namespace App\Support\Analytics;

use App\Models\Organization;
use App\Models\User;

/**
 * Step 17.2 — role scoping for organization analytics is per *section*, not per page. Every staff
 * role may open analytics; sections a viewer can't see are silently dropped (returned `null`)
 * rather than refused, so a saved report runs for everyone in the org and each person sees only
 * what their role allows. Abilities live on `OrganizationPolicy`:
 *
 * | Section                          | Owner | Strategist | Designer | Viewer |
 * |----------------------------------|-------|------------|----------|--------|
 * | clients, posts, approvals, team  |  ✓    |  ✓         |  ✓       |  ✓     |
 * | revenue                          |  ✓    |  ✓         |  –       |  –     |
 * | ai (counts)                      |  ✓    |  ✓         |  ✓       |  ✓     |
 * | ai.cost_estimate                 |  ✓    |  –         |  –       |  –     |
 *
 * Super admins pass every ability (`Gate::before`), which is what the console drill-down needs.
 */
final class AnalyticsAccess
{
    /**
     * The requested sections this viewer may see, in canonical order.
     *
     * @return list<string>
     */
    public static function visibleMetrics(User $user, OrganizationAnalyticsFilters $filters): array
    {
        $organization = self::organization($filters);
        $canViewRevenue = $user->can('viewRevenueAnalytics', $organization);

        return array_values(array_filter(
            $filters->selectedMetrics(),
            fn (string $metric) => $metric !== 'revenue' || $canViewRevenue,
        ));
    }

    public static function canViewRevenue(User $user, Organization|OrganizationAnalyticsFilters $target): bool
    {
        return $user->can('viewRevenueAnalytics', $target instanceof Organization ? $target : self::organization($target));
    }

    public static function canViewAiCost(User $user, Organization|OrganizationAnalyticsFilters $target): bool
    {
        return $user->can('viewAiCost', $target instanceof Organization ? $target : self::organization($target));
    }

    private static function organization(OrganizationAnalyticsFilters $filters): Organization
    {
        return Organization::query()->findOrFail($filters->orgId);
    }
}
