<?php

namespace App\Services\Plans;

use App\Models\Organization;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;

/**
 * Step CG-2.2 — the single place that counts an org's current usage against its plan limits, shared
 * by the enforcing Actions and the plan page so they can never diverge.
 *
 * Counts bypass only the tenant scope (`withoutOrgScope()`), never the `SoftDeletes` scope — a
 * disconnected account or a deleted draft frees its slot rather than permanently consuming it.
 */
class PlanUsage
{
    public function socialAccounts(Organization $organization): int
    {
        return SocialAccount::query()
            ->withoutOrgScope()
            ->where('org_id', $organization->id)
            ->count();
    }

    /**
     * Posts created in the current calendar month, in the org's own timezone.
     */
    public function scheduledPostsThisMonth(Organization $organization): int
    {
        $monthStartUtc = Carbon::now($organization->default_timezone)->startOfMonth()->utc();

        return Post::query()
            ->withoutOrgScope()
            ->where('org_id', $organization->id)
            ->where('created_at', '>=', $monthStartUtc)
            ->count();
    }
}
