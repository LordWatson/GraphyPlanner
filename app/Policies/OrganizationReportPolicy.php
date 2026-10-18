<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\OrganizationReport;
use App\Models\User;

/**
 * Step 17.6 — saved organization reports are shared with the whole org: anyone who can open
 * analytics can see and run them (each viewer only sees the sections their role allows — that's
 * applied when the report runs). Owners and Strategists build them; only the creator or an Owner
 * can change or delete one. Super admins pass via `Gate::before`.
 */
class OrganizationReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->org_id !== null && $this->isAnalyticsViewer($user);
    }

    public function view(User $user, OrganizationReport $report): bool
    {
        return $user->org_id === $report->org_id && $this->isAnalyticsViewer($user);
    }

    public function create(User $user): bool
    {
        return $user->org_id !== null && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    public function update(User $user, OrganizationReport $report): bool
    {
        return $user->org_id === $report->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true)
            && ($user->isOwner() || $report->created_by === $user->id);
    }

    public function delete(User $user, OrganizationReport $report): bool
    {
        return $this->update($user, $report);
    }

    private function isAnalyticsViewer(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer], true);
    }
}
