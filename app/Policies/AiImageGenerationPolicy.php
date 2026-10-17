<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\AiImageGeneration;
use App\Models\Client;
use App\Models\User;

/**
 * AI Image Studio access (Step 16.3). Owner/Strategist generate, refine, finalise and attach (the
 * same roles AiController::authorizeAiUse allows); Designer may view sets and download; a
 * ClientReviewer or Viewer gets nothing. A client only ever sees the final Asset once it is
 * attached to a post at "Waiting on client" or later.
 */
class AiImageGenerationPolicy
{
    /**
     * Whether the user can see a client's image sets.
     */
    public function viewAnyForClient(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id && in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
        ], true);
    }

    public function view(User $user, AiImageGeneration $generation): bool
    {
        return $user->org_id === $generation->org_id && in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
        ], true);
    }

    /**
     * Whether the user can start a new set for a client.
     */
    public function generate(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Refine, regenerate, make final, discard and attach a set's variants.
     */
    public function manage(User $user, AiImageGeneration $generation): bool
    {
        return $user->org_id === $generation->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }
}
