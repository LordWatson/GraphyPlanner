<?php

namespace App\Enums;

/**
 * The events an organization can subscribe an outgoing webhook to.
 *
 * Deliberately a short, closed list of *things that happened to a post or an order* — the moments
 * another system needs to react to. Not a change feed: "every field that changed on anything" is
 * easy to emit and impossible for a receiver to use, and it would make every internal rename a
 * breaking change for somebody else's integration.
 *
 * The backing strings are a wire format. A receiver switches on them, so they must never be
 * renamed — add a new one instead.
 */
enum WebhookEvent: string
{
    case PostApproved = 'post.approved';
    case PostChangesRequested = 'post.changes_requested';
    case PostPublished = 'post.published';
    case PostFailed = 'post.failed';
    /** Step S-5.5: an assisted Story is due and somebody has just been asked to post it by hand. */
    case StoryReadyToPost = 'story.ready_to_post';
    case OrderPaid = 'order.paid';

    public function label(): string
    {
        return match ($this) {
            self::PostApproved => 'A client approved a post',
            self::PostChangesRequested => 'A client requested changes',
            self::PostPublished => 'A post went live',
            self::PostFailed => 'A post failed to publish',
            self::StoryReadyToPost => 'A story is ready to post by hand',
            self::OrderPaid => 'A store order was paid',
        };
    }

    /**
     * The post status that produces this event, or null for events that are not a post transition.
     *
     * Here rather than in `TransitionPostStatusAction` so the list of emitted events and the list
     * of subscribable events are the same list — an event that nothing emits would otherwise be
     * offered to integrations and then never arrive, which is worse than not offering it.
     */
    public static function forPostStatus(PostStatus $status): ?self
    {
        return match ($status) {
            PostStatus::Approved => self::PostApproved,
            PostStatus::ChangesRequested => self::PostChangesRequested,
            PostStatus::Published, PostStatus::PartiallyPublished => self::PostPublished,
            PostStatus::Failed => self::PostFailed,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $event): string => $event->value, self::cases());
    }
}
