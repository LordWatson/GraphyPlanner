<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\StaffInvitation;
use App\Models\User;

class StaffInvitationPolicy
{
    /**
     * Determine whether the user can view/manage the organization's staff (Owner-only).
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id && $user->isOwner();
    }

    /**
     * Determine whether the user can invite a new staff member.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $this->viewAny($user, $organization);
    }

    /**
     * Determine whether the user can revoke an invitation.
     */
    public function delete(User $user, StaffInvitation $invitation): bool
    {
        return $user->org_id === $invitation->org_id && $user->isOwner();
    }
}
