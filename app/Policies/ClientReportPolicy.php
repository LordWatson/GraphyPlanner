<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientReport;
use App\Models\User;

/**
 * Step M-3.3 — monthly client reports. Every staff role of the organization can read them (they
 * carry no billing data); Owners and Strategists prepare, edit the commentary, publish and change
 * the schedule. A client reviewer sees only **published** reports of **their own** client.
 * Super admins pass via `Gate::before`.
 */
class ClientReportPolicy
{
    public function viewAny(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && $this->isStaff($user);
    }

    public function view(User $user, ClientReport $report): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $report->client_id && $report->isPublished();
        }

        return $user->org_id === $report->org_id && $this->isStaff($user);
    }

    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && $this->isEditor($user);
    }

    public function update(User $user, ClientReport $report): bool
    {
        return $user->org_id === $report->org_id && $this->isEditor($user) && ! $report->isPublished();
    }

    public function publish(User $user, ClientReport $report): bool
    {
        return $this->update($user, $report);
    }

    public function manageSchedule(User $user, Client $client): bool
    {
        return $this->create($user, $client);
    }

    private function isStaff(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer], true);
    }

    private function isEditor(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
