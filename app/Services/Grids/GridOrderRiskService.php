<?php

namespace App\Services\Grids;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Notifications\GridOrderAtRisk;
use Illuminate\Support\Facades\Log;

/**
 * Step 18.11 — "Grid order at risk": a grid post failed, was recalled or moved back out of
 * `scheduled` while a later tile (a lower position, which goes out after it) is still scheduled.
 * If nothing is done the later posts go out first and the profile ends up out of order.
 *
 * Checked whenever a grid post's status changes (`Post::booted`). The plan's creator is told once
 * (`order_risk_notified_at`), Home lists it, and the planner offers "Hold the rest". Nothing here
 * moves a post on its own.
 */
class GridOrderRiskService
{
    private const GOING_OUT = [PostStatus::Scheduled, PostStatus::Publishing];

    public function __construct(private readonly GridPlanService $grids = new GridPlanService) {}

    /**
     * @return array{tile_id: int, position: int, reason: string, later_post_ids: list<int>}|null
     */
    public function atRisk(GridPlan $plan): ?array
    {
        $this->grids->load($plan);
        $plan->tiles->each(fn (GridTile $tile) => $tile->post?->loadMissing('activityLogs'));

        // Publish order: the highest position goes out first.
        foreach ($plan->tiles->sortByDesc('position') as $tile) {
            $reason = $tile->post !== null ? $this->broken($plan, $tile, $tile->post) : null;

            if ($reason === null) {
                continue;
            }

            $later = array_values($plan->tiles
                ->filter(fn (GridTile $other) => $other->position < $tile->position
                    && $other->post !== null
                    && in_array($other->post->status, self::GOING_OUT, true)
                    && $this->grids->instagramTarget($plan, $other)?->status !== PostTargetStatus::Published)
                ->map(fn (GridTile $other) => (int) $other->post_id)
                ->all());

            if ($later !== []) {
                return ['tile_id' => $tile->id, 'position' => $tile->position, 'reason' => $reason, 'later_post_ids' => $later];
            }
        }

        return null;
    }

    /**
     * Re-check the grid a post belongs to after its status changed: tell the creator the first
     * time it goes at risk, and clear the flag once it's safe again.
     */
    public function checkForPost(Post $post): void
    {
        $tile = GridTile::query()->where('post_id', $post->id)->first();
        $plan = $tile !== null ? GridPlan::query()->withoutGlobalScopes()->find($tile->grid_plan_id) : null;

        if ($plan !== null) {
            $this->check($plan);
        }
    }

    public function check(GridPlan $plan): void
    {
        $risk = $this->atRisk($plan);

        if ($risk === null) {
            if ($plan->order_risk_notified_at !== null) {
                $plan->forceFill(['order_risk_notified_at' => null])->saveQuietly();
            }

            return;
        }

        if ($plan->order_risk_notified_at !== null) {
            return;
        }

        $plan->forceFill(['order_risk_notified_at' => now()])->saveQuietly();
        $plan->loadMissing('creator');

        Log::warning('Grid order at risk', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'grid_tile_id' => $risk['tile_id'],
            'later_post_count' => count($risk['later_post_ids']),
        ]);

        $plan->creator?->notify(new GridOrderAtRisk($plan, $risk['position'], $risk['reason']));
    }

    private function broken(GridPlan $plan, GridTile $tile, Post $post): ?string
    {
        if ($post->status === PostStatus::Failed || $this->grids->instagramTarget($plan, $tile)?->status === PostTargetStatus::Failed) {
            return "Tile {$tile->position} failed to publish.";
        }

        $wasScheduled = $post->activityLogs->contains(fn ($log) => in_array($log->from_status, self::GOING_OUT, true));

        if (in_array($post->status, PostStatus::preApproval(), true) || in_array($post->status, [PostStatus::Approved, PostStatus::Archived], true)) {
            return $wasScheduled ? "Tile {$tile->position} was taken off the schedule." : null;
        }

        return null;
    }
}
