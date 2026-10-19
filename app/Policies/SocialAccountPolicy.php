<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    /**
     * Determine whether the user can view the social accounts for a client.
     */
    public function viewAny(User $user, Client $client): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $client->id;
        }

        return $user->org_id === $client->org_id && in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
            Role::Viewer,
        ], true);
    }

    /**
     * Determine whether the user can view a specific social account.
     */
    public function view(User $user, SocialAccount $socialAccount): bool
    {
        return $this->viewAny($user, $socialAccount->client);
    }

    /**
     * Determine whether the user can create a social account for a client.
     */
    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can update a social account.
     */
    public function update(User $user, SocialAccount $socialAccount): bool
    {
        return $user->org_id === $socialAccount->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Step M-2.8: send a client a self-connect link (Owner/Strategist, like starting a connection).
     */
    public function createConnectInvite(User $user, Client $client): bool
    {
        return $this->create($user, $client);
    }

    /**
     * Step M-2.8: a client contact may connect their own client's accounts from the portal —
     * nothing else about the account opens up to them.
     */
    public function connectAsClient(User $user, SocialAccount $socialAccount): bool
    {
        return $user->role === Role::ClientReviewer
            && $user->client_id !== null
            && $user->client_id === $socialAccount->client_id
            && $user->org_id === $socialAccount->org_id;
    }

    /**
     * Determine whether the user can delete a social account.
     */
    public function delete(User $user, SocialAccount $socialAccount): bool
    {
        return $user->org_id === $socialAccount->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
