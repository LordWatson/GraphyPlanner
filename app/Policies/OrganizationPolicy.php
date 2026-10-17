<?php

namespace App\Policies;

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
