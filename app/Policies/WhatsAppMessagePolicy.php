<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Models\WhatsAppMessage;

/**
 * Step W-4.2 — who may read the WhatsApp message log.
 *
 * Owner and Strategist, matching who may set a client contact's consent in the first place
 * (`ClientContactPolicy`): the log says which of a client's people were messaged and when, which
 * is the same information as the consent itself. A Designer or Viewer has no reason to hold it,
 * and a client-portal reviewer certainly not.
 */
class WhatsAppMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->org_id !== null
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    public function view(User $user, WhatsAppMessage $message): bool
    {
        return $user->org_id === $message->org_id && $this->viewAny($user);
    }
}
