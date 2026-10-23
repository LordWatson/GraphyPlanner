<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\User;
use App\Support\Notifications\NotificationPreferences;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resolves the recipient audiences for post-related notifications and sends them. Kept as a
 * Service (rather than duplicating the same recipient queries in every Action/Job) since it's
 * reused by `CreatePostCommentAction`, `TransitionPostStatusAction`, and `PublishPostJob` — and,
 * since Phase 18, by the grid plan actions (a grid plan has the same org/client scoping).
 */
class PostNotificationService
{
    /**
     * Every internal org user who can see this post (Owner/Strategist/Designer/Viewer),
     * optionally excluding one user (typically the actor who triggered the event).
     *
     * @return Collection<int, User>
     */
    public function orgRecipients(Post|GridPlan $post, ?User $exclude = null): Collection
    {
        return User::query()
            ->where('org_id', $post->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
            ->get();
    }

    /**
     * Step B-3.3 — who hears that a post is waiting for internal review. In an agency that's every
     * internal user (`orgRecipients`, unchanged). In a business workspace with review turned on,
     * only the people who can act on it — Owners and Managers (`Role::Strategist`) — so a Creator
     * isn't pinged about work they can't approve.
     *
     * @return Collection<int, User>
     */
    public function internalReviewRecipients(Post $post, ?User $exclude = null): Collection
    {
        $post->loadMissing('organization');

        if (! $post->organization->isBusiness()) {
            // Step M-5.4: if the post has an internal reviewer and/or assignee, ping just them
            // instead of the whole org; otherwise fall back to every internal user (unchanged).
            $responsible = array_values(array_filter([$post->reviewer_id, $post->assignee_id]));

            if ($responsible !== []) {
                $targeted = User::query()
                    ->where('org_id', $post->org_id)
                    ->whereIn('id', $responsible)
                    ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
                    ->get();

                if ($targeted->isNotEmpty()) {
                    return $targeted;
                }
            }

            return $this->orgRecipients($post, $exclude);
        }

        return User::query()
            ->where('org_id', $post->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist])
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
            ->get();
    }

    /**
     * Every client-portal user (`Role::ClientReviewer`) scoped to this post's client,
     * optionally excluding one user.
     *
     * @return Collection<int, User>
     */
    public function clientPortalRecipients(Post|GridPlan $post, ?User $exclude = null): Collection
    {
        return User::query()
            ->where('client_id', $post->client_id)
            ->where('role', Role::ClientReviewer)
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
            ->get();
    }

    /**
     * Every user eligible to be `@`-mentioned in a comment on this post: the org's internal
     * users plus the post's client-portal users, optionally excluding one user (typically the
     * comment's author). Used to populate the mention autocomplete and to validate mention
     * tokens parsed out of a comment body before recording/notifying them.
     *
     * @return Collection<int, User>
     */
    public function mentionableRecipients(Post|GridPlan $post, ?User $exclude = null): Collection
    {
        return $this->orgRecipients($post, $exclude)
            ->merge($this->clientPortalRecipients($post, $exclude))
            ->values();
    }

    /**
     * Send a notification to a collection of recipients, skipping silently when empty.
     *
     * @param  Collection<int, User>  $recipients
     */
    public function send(Collection $recipients, Notification $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        // Step M-6.7: skip a recipient who has muted this notification's category in-app.
        foreach ($recipients as $recipient) {
            if (NotificationPreferences::wantsInApp($recipient, $notification::class)) {
                $recipient->notify($notification);
            }
        }
    }
}
