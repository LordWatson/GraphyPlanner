<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\User;

class ClientInvitationPolicy
{
    /**
     * Determine whether the user can view the invitations for a client.
     */
    public function viewAny(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can invite a contact for a client.
     */
    public function create(User $user, Client $client): bool
    {
        return $this->viewAny($user, $client);
    }

    /**
     * Determine whether the user can revoke an invitation.
     */
    public function delete(User $user, ClientInvitation $invitation): bool
    {
        return $user->org_id === $invitation->client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
