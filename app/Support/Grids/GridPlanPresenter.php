<?php

namespace App\Support\Grids;

use App\Actions\Grids\EvaluateGridChecklistAction;
use App\Contracts\ProfileFeedSource;
use App\Enums\PostTargetStatus;
use App\Models\Asset;
use App\Models\GridComment;
use App\Models\GridPlan;
use App\Models\GridPlanActivityLog;
use App\Models\GridTile;
use App\Models\Post;
use App\Services\Grids\GridDriftService;
use App\Services\Grids\GridOrderRiskService;
use App\Services\Grids\GridPlanService;
use App\Services\Grids\GridSequenceValidator;
use App\Support\Campaigns\CampaignPresenter;

/**
 * Phase 18 — the one place a grid plan becomes page props, so the planner, the review link and
 * the client portal all render the same `GridPreview` from the same shape.
 *
 * `forClient` drops everything a client must never see: team notes, internal comments, post ids
 * and statuses of work they haven't been sent, Image Studio details, costs and billing.
 */
class GridPlanPresenter
{
    public function __construct(
        private readonly GridPlanService $grids,
        private readonly ProfileFeedSource $feeds,
        private readonly GridDriftService $drift,
        private readonly EvaluateGridChecklistAction $checklist,
        private readonly GridOrderRiskService $risk,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(GridPlan $plan, bool $forClient = false): array
    {
        $this->grids->load($plan);
        $plan->loadMissing(['campaign', 'approver']);

        $outside = $this->grids->outsidePosts($plan);
        $layout = $this->grids->layout($plan, $outside);
        $driftedTiles = $forClient ? [] : $this->drift->driftedTiles($plan);

        $payload = [
            'id' => $plan->id,
            'name' => $plan->name,
            'status' => $plan->status->value,
            'status_label' => $plan->status->label(),
            'version' => $plan->version,
            'pinned_count' => $plan->pinned_count,
            'include_live_feed' => $plan->include_live_feed,
            'review_message' => $plan->review_message,
            'account' => [
                'id' => $plan->socialAccount->id,
                'handle' => $plan->socialAccount->handle,
                'display_name' => $plan->socialAccount->display_name,
                'timezone' => $plan->socialAccount->timezone,
            ],
            'approved_at' => $plan->approved_at?->toIso8601String(),
            'tiles' => $plan->tiles->map(fn (GridTile $tile) => $this->tile($plan, $tile, $layout, $forClient, $driftedTiles[$tile->id] ?? []))->values()->all(),
            'outside' => $this->outside($layout, $outside),
            'split_groups' => $layout->splitGroups,
            'progress' => $this->grids->progress($plan),
        ];

        if ($forClient) {
            return $payload;
        }

        return [
            ...$payload,
            'campaign' => $plan->campaign !== null ? CampaignPresenter::chip($plan->campaign) : null,
            'campaign_id' => $plan->campaign_id,
            'client_id' => $plan->client_id,
            'account' => [
                ...$payload['account'],
                'connection_status' => $plan->socialAccount->connection_status->value,
            ],
            'approved_by_name' => $plan->approver?->name,
            // Live, not the snapshot: a post moved on the calendar changes the dates check at once.
            'checklist' => ($this->checklist)($plan, $layout),
            'layout_drifted' => $plan->approved_layout_hash !== null && $plan->approved_layout_hash !== $this->grids->layoutHash($plan),
            'sequence_warnings' => array_map(fn (array $violation) => $violation['message'], GridSequenceValidator::violations($plan, $this->grids)),
            // Step 18.11: a failed or recalled post with later tiles still scheduled.
            'order_risk' => $this->risk->atRisk($plan),
        ];
    }

    /**
     * The live profile feed for the preview (only when the plan shows it).
     *
     * @return array<string, mixed>|null
     */
    public function feed(GridPlan $plan): ?array
    {
        $this->grids->load($plan);

        $feed = $this->feeds->recent($plan->socialAccount, (int) config('grids.feed_limit', 30));

        if (! $plan->include_live_feed) {
            return [...$feed->toArray(), 'items' => []];
        }

        return $feed->toArray();
    }

    /**
     * Comments for a surface: clients only ever see the shared ones.
     *
     * @return list<array<string, mixed>>
     */
    public function comments(GridPlan $plan, bool $forClient = false): array
    {
        $plan->loadMissing(['comments.user', 'comments.mentionedUsers', 'comments.tile']);

        return array_values($plan->comments
            ->when($forClient, fn ($comments) => $comments->where('internal_only', false))
            ->sortByDesc('created_at')
            ->map(fn (GridComment $comment) => [
                'id' => $comment->id,
                'user_name' => $comment->authorName(),
                'body' => $comment->body,
                'internal_only' => $comment->internal_only,
                'grid_tile_id' => $comment->grid_tile_id,
                'tile_position' => $comment->tile?->position,
                'mentioned_user_ids' => $comment->mentionedUsers->pluck('id')->values()->all(),
                'created_at' => $comment->created_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activity(GridPlan $plan): array
    {
        $plan->loadMissing('activityLogs.user');

        return array_values($plan->activityLogs
            ->sortByDesc('created_at')
            ->map(fn (GridPlanActivityLog $log) => [
                'id' => $log->id,
                'user_name' => $log->user?->name,
                'from_status' => $log->from_status?->value,
                'to_status' => $log->to_status->value,
                'note' => $log->note,
                'review_link_sent' => $log->review_token_id !== null,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @param  list<string>  $drift
     * @return array<string, mixed>
     */
    private function tile(GridPlan $plan, GridTile $tile, GridLayout $layout, bool $forClient, array $drift): array
    {
        $slot = $layout->tile($tile->id);
        $frame = $this->grids->frame($tile);
        $post = $tile->post;
        $target = $this->grids->instagramTarget($plan, $tile);
        $postVisible = $post !== null && (! $forClient || $post->status->isClientVisible());

        $payload = [
            'id' => $tile->id,
            'position' => $tile->position,
            'kind' => $tile->kind->value,
            'kind_label' => $tile->kind->label(),
            'format' => $tile->format->value,
            'slot' => $slot['slot'] ?? null,
            'row' => $slot['row'] ?? null,
            'column' => $slot['column'] ?? null,
            'sequence' => $slot['sequence'] ?? null,
            'split_group_id' => $tile->split_group_id,
            'placeholder_colour' => $tile->placeholder_colour?->value,
            'planned_local_date' => $tile->planned_local_date?->toDateString(),
            'planned_local_time' => $tile->planned_local_time,
            'publish_at' => $this->grids->publishAt($plan, $tile)?->toIso8601String(),
            'assets' => $tile->assets->map(fn (Asset $asset) => self::asset($asset))->values()->all(),
            'cover' => $tile->coverAsset !== null ? self::asset($tile->coverAsset) : null,
            'frame' => $frame?->toArray(),
            'caption' => $postVisible && $target !== null ? $target->setRelation('post', $post)->publishedCaption() : $tile->caption_idea,
            'live' => $target?->status === PostTargetStatus::Published,
        ];

        if ($forClient) {
            return $payload;
        }

        return [
            ...$payload,
            'team_note' => $tile->team_note,
            'caption_idea' => $tile->caption_idea,
            'post' => $post !== null ? [
                'id' => $post->id,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
                'target_status' => $target?->status->value,
            ] : null,
            'drift' => $drift,
        ];
    }

    /**
     * Outside posts in their real slots ("Not in this plan").
     *
     * @param  list<GridOutsidePost>  $outside
     * @return list<array<string, mixed>>
     */
    private function outside(GridLayout $layout, array $outside): array
    {
        if ($outside === []) {
            return [];
        }

        $posts = Post::query()->withoutGlobalScopes()->with('assets')
            ->whereKey(array_map(fn (GridOutsidePost $post) => $post->postId, $outside))
            ->get()
            ->keyBy('id');
        $times = collect($outside)->mapWithKeys(fn (GridOutsidePost $post) => [$post->postId => $post->publishAt->toIso8601String()]);

        return array_map(function (array $slot) use ($posts, $times) {
            /** @var Post|null $post */
            $post = $posts->get($slot['id']);
            /** @var Asset|null $first */
            $first = $post?->assets->first();

            return [
                'post_id' => $slot['id'],
                'slot' => $slot['slot'],
                'row' => $slot['row'],
                'column' => $slot['column'],
                'publish_at' => $times->get($slot['id']),
                'thumbnail_url' => $first?->thumbnailUrl(),
                'asset' => $first !== null ? self::asset($first) : null,
            ];
        }, $layout->outside());
    }

    /**
     * A tile's media item as the preview reads it (framing from the `grid_tile_assets` pivot).
     *
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        [$x, $y, $zoom] = GridPlanService::pivotFraming($asset);
        /** @var object{alt_text?: string|null}|null $pivot */
        $pivot = $asset->getRelationValue('pivot');

        return [
            'id' => $asset->id,
            'url' => $asset->url,
            'thumbnail_url' => $asset->thumbnailUrl(),
            'type' => $asset->type?->value,
            'width' => $asset->width,
            'height' => $asset->height,
            'original_filename' => $asset->original_filename,
            'alt_text' => $pivot->alt_text ?? null,
            'crop' => ['x' => $x, 'y' => $y, 'zoom' => $zoom],
        ];
    }
}
