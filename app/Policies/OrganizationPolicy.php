<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

/**
 * Super admins pass every check here through the `Gate::before` in `AppServiceProvider`
 * (Step 15.1) — the platform-level abilities below exist so a non-super-admin is always denied
 * explicitly, even if a `super-admin/*` route were ever exposed without its middleware.
 */
class OrganizationPolicy
{
    /**
     * Determine whether the user can view/update their organization's settings
     * (Upload-Post key, xAI key, default timezone). Owner-only, scoped to their own org.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id && $user->isOwner();
    }

    /**
     * Step 17.2: organization analytics — every staff role of the same org. Client reviewers
     * never (client-facing reporting is Phase 8). Sections are gated by the two abilities below.
     */
    public function viewAnalytics(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer], true);
    }

    /**
     * Step 17.2: the analytics `revenue` section — billing roles, mirroring `InvoicePolicy::viewLedger`.
     */
    public function viewRevenueAnalytics(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Step 17.2: AI cost estimates — Owner only, mirroring the Settings → AI Usage page.
     */
    public function viewAiCost(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id && $user->isOwner();
    }

    /**
     * Step 15.3: the cross-organization directory and onboarding — platform staff only.
     */
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->isSuperAdmin();
    }
}
