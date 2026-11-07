<?php

namespace App\Services;

use App\Contracts\ChatNotifier;
use App\Enums\Role;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\User;
use App\Notifications\ApprovalOverdue;
use App\Notifications\PostApprovalDecided;
use App\Notifications\PostPublishFailed;
use App\Notifications\PostWaitingForClientReview;
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

        $this->announceToChat($recipients->first(), $notification);
    }

    /**
     * The notifications worth interrupting a team channel for: a post going to the client, the
     * client's answer either way, a publish failing, and an approval going past its SLA. Anything
     * else stays in the bell — a channel that reports everything gets muted, and then it reports
     * nothing.
     *
     * @var list<class-string>
     */
    private const CHAT_ANNOUNCED = [
        PostWaitingForClientReview::class,
        PostApprovalDecided::class,
        PostPublishFailed::class,
        ApprovalOverdue::class,
    ];

    /**
     * Post one message per notification (not per recipient — it is a shared channel).
     *
     * Hooked here because every post notification already goes through `send()`, and every
     * notification's `toArray()` already carries the `title`, `body` and `url` a chat line needs.
     * No per-notification code, and nothing to forget when a new one is added — it simply will not
     * announce unless it is listed above.
     */
    private function announceToChat(?User $recipient, Notification $notification): void
    {
        // Checked before anything touches the database, so the notifications that are not
        // announced — most of them — cost nothing at all.
        if ($recipient === null || ! in_array($notification::class, self::CHAT_ANNOUNCED, true)) {
            return;
        }

        // Explicit: lazy loading is disabled app-wide, and the recipient collections reaching this
        // method do not all arrive with `organization` loaded.
        $organization = $recipient->loadMissing('organization')->organization;

        if ($organization === null) {
            return;
        }

        // Every announced notification stores to the database and so has a `toArray`, but the base
        // `Notification` does not promise one — so this asks rather than assumes. A notification
        // that cannot describe itself has nothing to say in a channel either.
        if (! method_exists($notification, 'toArray')) {
            return;
        }

        $chat = app(ChatNotifier::class);

        if (! $chat->isConfiguredFor($organization)) {
            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $notification->toArray($recipient);

        $chat->notifyOrganization(
            $organization,
            (string) ($payload['title'] ?? 'Update'),
            (string) ($payload['body'] ?? ''),
            isset($payload['url']) ? (string) $payload['url'] : null,
        );
    }
}
