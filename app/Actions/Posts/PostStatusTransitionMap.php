<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;

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
            PostStatus::Draft->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Publishing->value => [
            PostStatus::Published->value,
            PostStatus::Failed->value,
        ],
        PostStatus::Published->value => [
            PostStatus::Archived->value,
        ],
        PostStatus::Failed->value => [
            PostStatus::Draft->value,
            PostStatus::Scheduled->value,
            PostStatus::Archived->value,
        ],
        PostStatus::Archived->value => [
            PostStatus::Draft->value,
        ],
    ];

    public static function isAllowed(PostStatus $from, PostStatus $to): bool
    {
        return in_array($to->value, self::MAP[$from->value], true);
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
