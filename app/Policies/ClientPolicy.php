<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    /**
     * Determine whether the user can view the clients list.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
            Role::Viewer,
        ], true);
    }

    /**
     * Determine whether the user can view a specific client.
     */
    public function view(User $user, Client $client): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $client->id;
        }

        return $this->viewAny($user) && $user->org_id === $client->org_id;
    }

    /**
     * Determine whether the user can create clients.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can update a client.
     */
    public function update(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can archive ("delete") a client — Step I-1.5: a soft delete.
     */
    public function delete(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && $user->role === Role::Owner;
    }

    /**
     * Step I-1.5: bring an archived client back.
     */
    public function restore(User $user, Client $client): bool
    {
        return $client->trashed() && $this->delete($user, $client);
    }

    /**
     * Step I-1.5: permanently purge an archived client (blocked while it has invoices, see
     * `PurgeClientAction`). Only an already-archived client can be purged.
     */
    public function forceDelete(User $user, Client $client): bool
    {
        return $client->trashed() && $this->delete($user, $client);
    }

    /**
     * Determine whether the user can see billing-sensitive fields (retainer, billing cycle).
     */
    public function viewBilling(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
