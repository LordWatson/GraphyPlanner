<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\MentionKeyword;
use App\Models\User;

/**
 * Watched words are the studio's own triage settings: the producing roles manage them, a Viewer can
 * see what is watched, and a `ClientReviewer` never reaches the inbox at all. Mirrors
 * `SavedReplyPolicy`, which governs the other per-org inbox setting.
 */
class MentionKeywordPolicy
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

    public function delete(User $user, MentionKeyword $keyword): bool
    {
        return ! $user->isSuperAdmin()
            && $user->org_id === $keyword->org_id
            && in_array($user->role, self::MANAGERS, true);
    }
}
