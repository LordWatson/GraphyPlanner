<?php

namespace App\Policies;

use App\Models\User;

class StaffPolicy
{
    /**
     * Determine whether the user can view the organization's staff roster (Owner-only).
     */
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    /**
     * Determine whether the user can view a specific staff member's profile/history.
     */
    public function view(User $user, User $staff): bool
    {
        return $user->org_id === $staff->org_id && $user->isOwner();
    }

    /**
     * Determine whether the user can edit a staff member's name/role.
     */
    public function update(User $user, User $staff): bool
    {
        return $user->org_id === $staff->org_id && $user->isOwner();
    }

    /**
     * Determine whether the user can remove a staff member from the organization. Owners can't
     * remove themselves (they'd lock themselves out) or another Owner while there is no other
     * Owner left to run the org.
     */
    public function delete(User $user, User $staff): bool
    {
        if ($user->org_id !== $staff->org_id || ! $user->isOwner()) {
            return false;
        }

        if ($user->is($staff)) {
            return false;
        }

        if ($staff->isOwner() && User::query()->where('org_id', $staff->org_id)->where('role', 'owner')->count() <= 1) {
            return false;
        }

        return true;
    }
}
