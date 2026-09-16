<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

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
}
