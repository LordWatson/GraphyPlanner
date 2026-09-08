<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Client;
use App\Models\User;

class AssetPolicy
{
    /**
     * Determine whether the user can view the assets for a client.
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
     * Determine whether the user can view a specific asset.
     */
    public function view(User $user, Asset $asset): bool
    {
        return $this->viewAny($user, $asset->client);
    }

    /**
     * Determine whether the user can upload/link an asset for a client.
     *
     * Per spec §3, Designer is restricted to assets/Figma links, so Designer
     * is allowed here (unlike e.g. SocialAccountPolicy/CampaignPolicy).
     */
    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
        ], true);
    }

    /**
     * Determine whether the user can delete an asset.
     */
    public function delete(User $user, Asset $asset): bool
    {
        if ($user->org_id !== $asset->org_id) {
            return false;
        }

        if (in_array($user->role, [Role::Owner, Role::Strategist], true)) {
            return true;
        }

        return $user->role === Role::Designer && $asset->uploaded_by === $user->id;
    }
}
