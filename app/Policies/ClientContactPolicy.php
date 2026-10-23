<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;

/**
 * Step M-6.1 — managing client contacts mirrors managing portal invitations: Owner/Strategist in
 * the client's own organization, and never a business workspace (contacts belong to the agency CRM).
 */
class ClientContactPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->organization?->isBusiness() === true ? false : null;
    }

    public function viewAny(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    public function create(User $user, Client $client): bool
    {
        return $this->viewAny($user, $client);
    }

    public function update(User $user, ClientContact $contact): bool
    {
        return $user->org_id === $contact->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    public function delete(User $user, ClientContact $contact): bool
    {
        return $this->update($user, $contact);
    }
}
