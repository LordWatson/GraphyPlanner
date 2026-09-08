<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Models\User;

class BrandBrainPolicy
{
    /**
     * Determine whether the user can view a client's brand brain.
     */
    public function view(User $user, BrandBrain $brandBrain): bool
    {
        return $this->viewForClient($user, $brandBrain->client);
    }

    /**
     * Determine whether the user can view the brand brain for the given client
     * (used before the `BrandBrain` row necessarily exists yet).
     */
    public function viewForClient(User $user, Client $client): bool
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
     * Determine whether the user can create/update a client's brand brain.
     */
    public function update(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
