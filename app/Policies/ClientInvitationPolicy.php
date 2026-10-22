<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientInvitation;
use App\Models\User;

class ClientInvitationPolicy
{
    /**
     * Step B-1.4 — the client portal is agency-only, so a business workspace can never invite a
     * client contact. Denies every ability here (defence in depth behind `workspace:agency`);
     * null leaves super admins and agencies to the per-ability checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->organization?->isBusiness() === true ? false : null;
    }

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
        // Explicit `loadMissing` (not a bare `->client` access) so this never trips the
        // app-wide lazy-loading guard regardless of whether the caller eager-loaded `client`.
        return $user->org_id === $invitation->loadMissing('client')->client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
