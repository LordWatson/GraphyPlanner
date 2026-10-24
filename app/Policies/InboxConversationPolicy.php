<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\InboxConversation;
use App\Models\User;

/**
 * Step M-9.2 — who can use the social inbox. It's an internal community-management surface that
 * replies to the public, so a `Role::ClientReviewer` is excluded entirely (belt-and-braces with the
 * `portal.redirect` middleware). `Viewer` may read the inbox but not act on it; the producing roles
 * (Owner, Strategist, Designer) can assign, snooze/resolve and send replies.
 *
 * `view` also enforces tenant isolation: a bound conversation from another org reaches here (the
 * route binding lifts the org scope) and is refused, exactly as `CrossTenantRouteTest` requires.
 */
class InboxConversationPolicy
{
    /** @var array<int, Role> */
    private const READERS = [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer];

    /** @var array<int, Role> */
    private const ACTORS = [Role::Owner, Role::Strategist, Role::Designer];

    public function viewAny(User $user): bool
    {
        return in_array($user->role, self::READERS, true);
    }

    public function view(User $user, InboxConversation $conversation): bool
    {
        return $this->sameOrg($user, $conversation) && in_array($user->role, self::READERS, true);
    }

    public function act(User $user, InboxConversation $conversation): bool
    {
        return $this->sameOrg($user, $conversation) && in_array($user->role, self::ACTORS, true);
    }

    public function reply(User $user, InboxConversation $conversation): bool
    {
        return $this->act($user, $conversation);
    }

    private function sameOrg(User $user, InboxConversation $conversation): bool
    {
        return ! $user->isSuperAdmin() && $user->org_id === $conversation->org_id;
    }
}
