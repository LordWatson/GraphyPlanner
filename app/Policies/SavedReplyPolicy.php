<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SavedReply;
use App\Models\User;

/**
 * Step M-9.2 — canned inbox replies are managed by the producing roles (Owner, Strategist,
 * Designer). `Viewer` can read them (to use in a reply draft they can't send) but not edit them;
 * `ClientReviewer` never sees the inbox at all.
 */
class SavedReplyPolicy
{
    /** @var array<int, Role> */
    private const READERS = [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer];

    /** @var array<int, Role> */
    private const MANAGERS = [Role::Owner, Role::Strategist, Role::Designer];

    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::READERS, true);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, self::MANAGERS, true);
    }

    public function update(User $user, SavedReply $reply): bool
    {
        return ! $user->isSuperAdmin()
            && $user->org_id === $reply->org_id
            && in_array($user->role, self::MANAGERS, true);
    }

    public function delete(User $user, SavedReply $reply): bool
    {
        return $this->update($user, $reply);
    }
}
