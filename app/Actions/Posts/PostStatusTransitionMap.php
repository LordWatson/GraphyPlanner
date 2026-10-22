<?php

namespace App\Actions\Posts;

use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use App\Models\Post;

/**
 * The explicit `Post::status` state machine (spec §4.6 / plan Step 0.9). Kept as a single
 * first-class map rather than scattered if/else so every valid transition is visible at a glance.
 */
class PostStatusTransitionMap
{
    /**
     * @var array<string, array<int, string>>
     */
    public const MAP = [
        PostStatus::Idea->value => [
            PostStatus::Draft->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Draft->value => [
            PostStatus::InternalReview->value,
            PostStatus::Archived->value,
        ],
        PostStatus::InternalReview->value => [
            PostStatus::Draft->value,
            PostStatus::WaitingClient->value,
            PostStatus::Approved->value,
            PostStatus::Archived->value,
        ],
        PostStatus::WaitingClient->value => [
            PostStatus::Approved->value,
            PostStatus::ChangesRequested->value,
            PostStatus::Archived->value,
        ],
        PostStatus::ChangesRequested->value => [
            PostStatus::InternalReview->value,
            PostStatus::Draft->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Approved->value => [
            PostStatus::Scheduled->value,
            PostStatus::Draft->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Scheduled->value => [
            PostStatus::Publishing->value,
            // Campaigns enhancement C-6.1: "Unschedule" keeps the approval (pausing a campaign
            // uses it). Leaving `scheduled` clears the dispatch id, so the queued job no-ops.
            PostStatus::Approved->value,
            PostStatus::Draft->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Publishing->value => [
            PostStatus::Published->value,
            PostStatus::PartiallyPublished->value,
            PostStatus::Failed->value,
            // Step I-3.6 / M-2.5: "Unschedule" while every channel is still held by the vendor
            // for later (`Post::canBeRecalled()`); the held publishes are cancelled first.
            PostStatus::Approved->value,
            PostStatus::Draft->value,
        ],
        PostStatus::Published->value => [
            PostStatus::Archived->value,
        ],
        PostStatus::PartiallyPublished->value => [
            // Step I-1.3: "Retry failed targets" (`RetryFailedPostTargetsAction`) only.
            PostStatus::Publishing->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Failed->value => [
            PostStatus::Draft->value,
            PostStatus::Scheduled->value,
            // Step I-1.3: "Retry failed targets" (`RetryFailedPostTargetsAction`) only.
            PostStatus::Publishing->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Archived->value => [
            PostStatus::Draft->value,
        ],
    ];

    /**
     * Moves that only the publishing pipeline may make: `publishing` can be entered by hand from
     * `scheduled` (a manual override), but from `failed`/`partially_published` it means "retry the
     * failed targets", which must go through `RetryFailedPostTargetsAction` so the target jobs are
     * actually dispatched.
     */
    public static function isRetryMove(PostStatus $from, PostStatus $to): bool
    {
        return $to === PostStatus::Publishing
            && in_array($from, [PostStatus::Failed, PostStatus::PartiallyPublished], true);
    }

    /**
     * Step I-3.6 / M-2.5: taking a `publishing` post back off the schedule — only allowed while
     * nothing has gone out (`Post::canBeRecalled()`), and it cancels the vendor-held channels.
     */
    public static function isRecallMove(PostStatus $from, PostStatus $to): bool
    {
        return $from === PostStatus::Publishing && in_array($to, [PostStatus::Approved, PostStatus::Draft], true);
    }

    /**
     * Taking a `scheduled` post off the schedule (unschedule, back to draft, archive) — anything
     * but it going out. Its channels may already be held by the vendor
     * (`SendScheduledPostToVendorJob`), so they're cancelled there first.
     */
    public static function leavesSchedule(PostStatus $from, PostStatus $to): bool
    {
        return $from === PostStatus::Scheduled && $to !== PostStatus::Publishing;
    }

    public static function isAllowed(PostStatus $from, PostStatus $to): bool
    {
        return in_array($to->value, self::MAP[$from->value], true);
    }

    /**
     * Step I-3.8 / M-2.9: the move for this particular post. A `client_required` post can only be
     * approved through the client (`waiting_client`); skipping straight from internal review is an
     * Owner-only override with a reason (`TransitionPostStatusAction::overrideApproval()`).
     */
    public static function isAllowedFor(Post $post, PostStatus $to, ?PostStatus $from = null): bool
    {
        $from ??= $post->status;

        return self::isAllowed($from, $to) && ! self::needsClientApproval($post, $from, $to);
    }

    /**
     * Whether this move would approve a `client_required` post without the client.
     */
    public static function needsClientApproval(Post $post, PostStatus $from, PostStatus $to): bool
    {
        return $post->approval_mode === ApprovalMode::ClientRequired
            && $from === PostStatus::InternalReview
            && $to === PostStatus::Approved;
    }

    /**
     * @return list<PostStatus>
     */
    public static function allowedFor(Post $post): array
    {
        return array_values(array_filter(self::allowedFrom($post->status), fn (PostStatus $to) => self::isAllowedFor($post, $to)));
    }

    /**
     * @return array<int, PostStatus>
     */
    public static function allowedFrom(PostStatus $from): array
    {
        return array_map(
            static fn (string $value): PostStatus => PostStatus::from($value),
            self::MAP[$from->value],
        );
    }
}
