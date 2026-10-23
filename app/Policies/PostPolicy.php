<?php

namespace App\Policies;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
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
     *
     * A post that is `scheduled` or `published` is locked for content edits: its inputs must
     * stay frozen until a user transitions it to a different status (spec §6). Status transitions
     * themselves go through `transition()` instead, so this only blocks the content-editing form.
     */
    public function update(User $user, Post $post): bool
    {
        if (in_array($post->status, [PostStatus::Scheduled, PostStatus::Published, PostStatus::PartiallyPublished], true)) {
            return false;
        }

        // Step I-3.6 / M-2.5: a post that's going out is locked too, unless nothing has gone out
        // yet — then an edit recalls the vendor-held channels and re-submits them.
        if ($post->status === PostStatus::Publishing && ! $post->canBeRecalled()) {
            return false;
        }

        return $user->org_id === $post->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Determine whether the user can move a post to another day from the calendar.
     *
     * Step I-1.2: a `scheduled` post can be moved — `ReschedulePostAction` re-dispatches its
     * publish job with a fresh dispatch id, so the old queued job no-ops. Still blocked: anything
     * that has gone (or is going) out — `publishing`, `published`, `partially_published` —
     * `archived`, and any post with a target already published, since shifting a published
     * target's date would rewrite history.
     */
    public function reschedule(User $user, Post $post): bool
    {
        // Step I-3.6 / M-2.5: a post the vendor is still holding for later can move — its held
        // channels are recalled and re-submitted at the new times.
        if (in_array($post->status, self::NON_RESCHEDULABLE_STATUSES, true) && ! $post->canBeRecalled()) {
            return false;
        }

        if ($post->targets->contains(fn ($target) => $target->status === PostTargetStatus::Published)) {
            return false;
        }

        return $user->org_id === $post->org_id && self::canRescheduleAny($user);
    }

    /**
     * Statuses a post can never be dragged out of. Shared with `Post::scopeReschedulable`, the
     * set-based form of this rule the calendar uses (Step I-2.3).
     *
     * @var list<PostStatus>
     */
    public const NON_RESCHEDULABLE_STATUSES = [
        PostStatus::Publishing,
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Archived,
    ];

    /**
     * The role half of `reschedule()`, for callers that check posts as a set.
     */
    public static function canRescheduleAny(User $user): bool
    {
        return in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Determine whether the user can delete a post (Step I-1.5: a soft delete via
     * `DeletePostAction`). Anything that has gone — or is going — out can only be archived:
     * `publishing`, `published`, `partially_published`, or any post with a published target.
     */
    public function delete(User $user, Post $post): bool
    {
        if (in_array($post->status, [PostStatus::Publishing, PostStatus::Published, PostStatus::PartiallyPublished], true)) {
            return false;
        }

        if ($post->targets->contains(fn ($target) => $target->status === PostTargetStatus::Published)) {
            return false;
        }

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
        // Step I-1.3: retrying failed targets has its own action/route (`retryFailedTargets`).
        if (PostStatusTransitionMap::isRetryMove($post->status, $to)) {
            return false;
        }

        if ($user->role === Role::ClientReviewer) {
            return $user->client_id === $post->client_id
                && $post->status === PostStatus::WaitingClient
                && in_array($to, [PostStatus::Approved, PostStatus::ChangesRequested], true);
        }

        // Step I-3.6 / M-2.5: only while nothing has gone out yet.
        if (PostStatusTransitionMap::isRecallMove($post->status, $to) && ! $post->canBeRecalled()) {
            return false;
        }

        return $user->org_id === $post->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * Step I-3.8 / M-2.9: approve a `client_required` post without the client — Owners only, from
     * internal review, with a reason (`TransitionPostStatusAction::overrideApproval()`).
     */
    public function overrideApproval(User $user, Post $post): bool
    {
        return $user->role === Role::Owner
            && $user->org_id === $post->org_id
            && PostStatusTransitionMap::needsClientApproval($post, $post->status, PostStatus::Approved);
    }

    /**
     * Step I-1.3: resend only the failed targets of a `failed`/`partially_published` post.
     */
    public function retryFailedTargets(User $user, Post $post): bool
    {
        return in_array($post->status, [PostStatus::Failed, PostStatus::PartiallyPublished], true)
            && $user->org_id === $post->org_id
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
     * Step B-3.4 — mint a read-only "Share preview" link (a partner/sponsor link). Sharing a post
     * outside the workspace is a trust action, so it's limited to Owners and Managers
     * (`Role::Strategist`) in the post's own org, never a Creator/Viewer or another org.
     */
    public function sharePreview(User $user, Post $post): bool
    {
        return $user->org_id === $post->org_id
            && in_array($user->role, [Role::Owner, Role::Strategist], true);
    }

    /**
     * Determine whether the user can re-send a stored review-portal link email (from a
     * `waiting_client` activity log entry) to the client. Uses the same roles as `update`, since
     * this is an org-side action to help the client re-discover the review link.
     */
    public function resendReviewEmail(User $user, Post $post): bool
    {
        return $this->update($user, $post);
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

    /**
     * Step M-5.1 — the org-wide content hub (`/content`): every post across every client, as a
     * table or a status board. An internal working surface (filter, bulk-move, assign), so a
     * `Role::ClientReviewer` is excluded, the same as Home. The hub query re-applies the org scope,
     * and each bulk move is re-checked through `transition()`.
     */
    public function viewHub(User $user): bool
    {
        return $this->viewHome($user);
    }
}
