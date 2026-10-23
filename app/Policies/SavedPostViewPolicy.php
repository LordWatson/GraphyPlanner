<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SavedPostView;
use App\Models\User;

/**
 * Step M-5.1 — saved content-hub views are personal. Any staff member who can open the hub may keep
 * their own views; a member can only ever see or delete their own, and a client reviewer never has
 * any (the content hub is an internal surface, like Home).
 */
class SavedPostViewPolicy
{
    private const STAFF = [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer];

    public function create(User $user): bool
    {
        return in_array($user->role, self::STAFF, true);
    }

    public function view(User $user, SavedPostView $view): bool
    {
        return $user->id === $view->user_id && $user->org_id === $view->org_id;
    }

    public function delete(User $user, SavedPostView $view): bool
    {
        return $this->view($user, $view);
    }
}
