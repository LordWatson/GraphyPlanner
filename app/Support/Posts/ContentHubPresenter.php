<?php

namespace App\Support\Posts;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Support\Campaigns\CampaignPresenter;

/**
 * Step M-5.1 — shapes a post for the content hub (`/content`). The table row and the board card
 * share the same core (status, caption, client, schedule, thumbnail); the board card adds the
 * legal drop targets for this viewer so a drag only ever offers a move the state machine and the
 * viewer's role allow (re-validated server-side on drop).
 */
class ContentHubPresenter
{
    /**
     * Statuses a drag may drop onto, mirroring the campaign board (`GetCampaignBoardAction`):
     * publishing outcomes belong to the pipeline, and archiving is a bulk action, not a column.
     *
     * @var list<PostStatus>
     */
    public const DROPPABLE = [
        PostStatus::Draft, PostStatus::InternalReview, PostStatus::WaitingClient, PostStatus::ChangesRequested,
        PostStatus::Approved, PostStatus::Scheduled,
    ];

    /**
     * Board columns in work order. Publishing/partially-published fold into their neighbours.
     *
     * @var array<string, list<PostStatus>>
     */
    public const COLUMNS = [
        'idea' => [PostStatus::Idea],
        'draft' => [PostStatus::Draft],
        'internal_review' => [PostStatus::InternalReview],
        'waiting_client' => [PostStatus::WaitingClient],
        'changes_requested' => [PostStatus::ChangesRequested],
        'approved' => [PostStatus::Approved],
        'scheduled' => [PostStatus::Scheduled, PostStatus::Publishing],
        'published' => [PostStatus::Published, PostStatus::PartiallyPublished],
        'failed' => [PostStatus::Failed],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function row(Post $post, User $user): array
    {
        return [
            ...self::core($post),
            'client' => ['id' => $post->client_id, 'name' => $post->client?->name],
            'owner' => $post->creator?->name,
            // Step M-5.4: who it's assigned to and when it's due.
            'assignee' => $post->assignee?->name,
            'due_at' => $post->due_at?->toIso8601String(),
            'has_media' => $post->assets->isNotEmpty(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'can' => [
                'update' => $user->can('update', $post),
                // Step M-5.2: duplicate needs create rights on the client, not update — so a locked
                // published post can still be recycled into a fresh draft.
                'duplicate' => $post->client !== null && $user->can('create', [Post::class, $post->client]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function boardCard(Post $post, User $user): array
    {
        return [
            ...self::core($post),
            'client' => ['id' => $post->client_id, 'name' => $post->client?->name],
            'drop_targets' => self::dropTargets($post, $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function core(Post $post): array
    {
        $first = $post->targets
            ->filter(fn (PostTarget $target) => $target->scheduled_local_date !== null)
            ->sortBy(fn (PostTarget $target) => $target->scheduled_at_utc?->getTimestamp() ?? PHP_INT_MAX)
            ->first();

        return [
            'id' => $post->id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'master_caption' => $post->master_caption,
            'campaign' => $post->campaign ? CampaignPresenter::chip($post->campaign) : null,
            'thumbnail_url' => $post->assets->first()?->thumbnailUrl(),
            'targets' => $post->targets->map(fn (PostTarget $target) => [
                'platform' => $target->socialAccount?->platform?->value,
                'handle' => $target->socialAccount?->handle,
            ])->values()->all(),
            'scheduled_local_date' => $first?->scheduled_local_date?->toDateString(),
            'scheduled_local_time' => $first?->scheduled_local_time,
            'scheduled_at_utc' => $first?->scheduled_at_utc?->toIso8601String(),
        ];
    }

    /**
     * The columns this post may be dragged onto, for this viewer (role + state machine).
     *
     * @return list<string>
     */
    private static function dropTargets(Post $post, User $user): array
    {
        return array_values(array_map(
            fn (PostStatus $status) => $status->value,
            array_filter(
                PostStatusTransitionMap::allowedFor($post),
                fn (PostStatus $to) => in_array($to, self::DROPPABLE, true) && $user->can('transition', [$post, $to]),
            ),
        ));
    }
}
