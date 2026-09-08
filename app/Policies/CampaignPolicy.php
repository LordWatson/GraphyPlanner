<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;

class CampaignPolicy
{
    /**
     * Determine whether the user can view the campaigns for a client.
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
     * Determine whether the user can view a specific campaign.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->viewAny($user, $campaign->client);
    }

    /**
     * Determine whether the user can create a campaign for a client.
     */
    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can update a campaign.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        return $user->org_id === $campaign->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can delete a campaign.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        return $user->org_id === $campaign->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
