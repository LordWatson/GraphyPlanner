<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Post;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Resolves the recipient audiences for post-related notifications and sends them. Kept as a
 * Service (rather than duplicating the same recipient queries in every Action/Job) since it's
 * reused by `CreatePostCommentAction`, `TransitionPostStatusAction`, and `PublishPostJob`.
 */
class PostNotificationService
{
    /**
     * Every internal org user who can see this post (Owner/Strategist/Designer/Viewer),
     * optionally excluding one user (typically the actor who triggered the event).
     *
     * @return Collection<int, User>
     */
    public function orgRecipients(Post $post, ?User $exclude = null): Collection
    {
        return User::query()
            ->where('org_id', $post->org_id)
            ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
            ->get();
    }

    /**
     * Every client-portal user (`Role::ClientReviewer`) scoped to this post's client,
     * optionally excluding one user.
     *
     * @return Collection<int, User>
     */
    public function clientPortalRecipients(Post $post, ?User $exclude = null): Collection
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
    public function mentionableRecipients(Post $post, ?User $exclude = null): Collection
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

        foreach ($recipients as $recipient) {
            $recipient->notify($notification);
        }
    }
}
