<?php

namespace App\Actions\Campaigns;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Enums\PostStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\Post;
use App\Models\User;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;

/**
 * Step C-4.3 — the campaign board: every live post in the campaign grouped by status, plus the
 * planned-but-not-started deliverables as an extra "Not started" column. Each post carries the
 * columns it may legally be dropped on, from `PostStatusTransitionMap` filtered by the viewer's
 * `PostPolicy::transition` — a Viewer gets none, so the board is read-only for them. The server
 * re-validates every move (`CampaignBoardController::move`).
 */
class GetCampaignBoardAction
{
    /**
     * Columns in work order. Publishing/partially published fold into their neighbours; archived
     * posts are off the board.
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
     * Destinations a drag may offer. Publishing outcomes belong to the pipeline, and archiving is
     * done from the post itself, so neither is a drop target.
     */
    private const DROPPABLE = [
        PostStatus::Draft, PostStatus::InternalReview, PostStatus::WaitingClient, PostStatus::ChangesRequested,
        PostStatus::Approved, PostStatus::Scheduled,
    ];

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Campaign $campaign, User $user): array
    {
        $posts = $campaign->posts()
            ->where('status', '!=', PostStatus::Archived)
            ->with(['targets.socialAccount', 'deliverable.assignee:id,name'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $today = CampaignClock::today($campaign);
        $leadDays = (int) $campaign->client->approval_lead_days;

        $notStarted = $campaign->deliverables()
            ->whereNull('post_id')
            ->with(['assignee:id,name'])
            ->orderBy('planned_for')
            ->orderBy('sort')
            ->get()
            ->map(fn (CampaignDeliverable $deliverable) => GetCampaignPlanAction::deliverable($deliverable, $leadDays, $today))
            ->values()
            ->all();

        $columns = [];

        foreach (self::COLUMNS as $key => $statuses) {
            $columns[] = [
                'key' => $key,
                'label' => $statuses[0]->label(),
                'drop_status' => in_array($statuses[0], self::DROPPABLE, true) ? $statuses[0]->value : null,
                'posts' => $posts
                    ->filter(fn (Post $post) => in_array($post->status, $statuses, true))
                    ->map(fn (Post $post) => [
                        ...CampaignPresenter::post($post),
                        'assignee' => $post->deliverable?->assignee?->name,
                        'drop_targets' => $this->dropTargets($post, $user),
                        'can_edit' => $user->can('update', $post),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return [
            'columns' => $columns,
            'notStarted' => $notStarted,
            'phases' => $campaign->phases()->get()->map(fn ($phase) => GetCampaignPlanAction::phase($phase))->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function dropTargets(Post $post, User $user): array
    {
        return array_values(array_map(
            fn (PostStatus $status) => $status->value,
            array_filter(
                PostStatusTransitionMap::allowedFrom($post->status),
                fn (PostStatus $to) => in_array($to, self::DROPPABLE, true) && $user->can('transition', [$post, $to]),
            ),
        ));
    }
}
