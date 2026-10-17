<?php

namespace App\Policies;

use App\Models\SuperAdminReport;
use App\Models\User;

/**
 * Step 15.5 — saved platform reports are platform staff only. Super admins are granted every
 * ability by the `Gate::before` in `AppServiceProvider`; everyone else is denied here.
 */
class SuperAdminReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SuperAdminReport $report): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SuperAdminReport $report): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SuperAdminReport $report): bool
    {
        return $user->isSuperAdmin();
    }
}
