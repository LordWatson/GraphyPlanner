<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Client;
use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    /**
     * Determine whether the user can view the posts for a client.
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
     * Determine whether the user can view a specific post.
     */
    public function view(User $user, Post $post): bool
    {
        return $this->viewAny($user, $post->client);
    }

    /**
     * Determine whether the user can create a post for a client.
     */
    public function create(User $user, Client $client): bool
    {
        return $user->org_id === $client->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Determine whether the user can update a post.
     */
    public function update(User $user, Post $post): bool
    {
        return $user->org_id === $post->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Determine whether the user can delete a post.
     */
    public function delete(User $user, Post $post): bool
    {
        return $user->org_id === $post->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can transition a post to the given status.
     *
     * Client reviewers may only decide on a post that is `waiting_client` for their own client,
     * moving it to `approved` or `changes_requested` (the review-portal actions, spec §3/§0.13).
     * Everyone else is limited to the org's internal roles allowed to edit content.
     */
    public function transition(User $user, Post $post, PostStatus $to): bool
    {
        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $post->client_id
                && $post->status === PostStatus::WaitingClient
                && in_array($to, [PostStatus::Approved, PostStatus::ChangesRequested], true);
        }

        return $user->org_id === $post->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Determine whether the user can comment on a post.
     */
    public function comment(User $user, Post $post): bool
    {
        return $this->view($user, $post);
    }

    /**
     * Determine whether the user can view the org-wide calendar (Step 0.11). Every role that can
     * see posts at all may open the calendar; a `Role::ClientReviewer` may open it too, but the
     * `CalendarController` query itself scopes their results down to their own `client_id` (same
     * restriction pattern as `viewAny`/`view`).
     */
    public function viewCalendar(User $user): bool
    {
        return in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
            Role::Viewer,
            Role::ClientReviewer,
        ], true);
    }

    /**
     * Determine whether the user can view the Home / needs-attention dashboard (Step 0.12).
     * Unlike the calendar, this is an internal ops view (failed publishes, missing media,
     * disconnected accounts) with no client-facing purpose, so `Role::ClientReviewer` is excluded.
     */
    public function viewHome(User $user): bool
    {
        return in_array($user->role, [
            Role::Owner,
            Role::Strategist,
            Role::Designer,
            Role::Viewer,
        ], true);
    }
}
