<?php

namespace App\Actions\Grids;

use App\Enums\GridPlanStatus;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use App\Services\Grids\GridPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DuplicateGridPlanAction
{
    public function __construct(private readonly EvaluateGridChecklistAction $checklist = new EvaluateGridChecklistAction) {}

    /**
     * Step 18.4 — copy a grid as a fresh draft: same account, campaign, settings, tiles, media and
     * framing (split groups get new ids). Never copied: posts, dates, comments, activity or the
     * approval — the copy is a new plan to review.
     */
    public function __invoke(GridPlan $plan, User $user): GridPlan
    {
        return DB::transaction(function () use ($plan, $user) {
            $plan->loadMissing('tiles.assets');

            $copy = GridPlan::create([
                'org_id' => $plan->org_id,
                'client_id' => $plan->client_id,
                'social_account_id' => $plan->social_account_id,
                'campaign_id' => $plan->campaign_id,
                'name' => Str::limit($plan->name, 240, '').' (copy)',
                'status' => GridPlanStatus::Draft,
                'review_message' => $plan->review_message,
                'pinned_count' => $plan->pinned_count,
                'include_live_feed' => $plan->include_live_feed,
                'version' => 1,
                'created_by' => $user->id,
            ]);

            $groups = [];

            foreach ($plan->tiles as $tile) {
                /** @var GridTile $new */
                $new = $copy->tiles()->create([
                    'position' => $tile->position,
                    'kind' => $tile->kind,
                    'format' => $tile->format,
                    'cover_asset_id' => $tile->cover_asset_id,
                    'placeholder_colour' => $tile->placeholder_colour,
                    'team_note' => $tile->team_note,
                    'caption_idea' => $tile->caption_idea,
                    'split_group_id' => $tile->split_group_id === null
                        ? null
                        : ($groups[$tile->split_group_id] ??= (string) Str::uuid()),
                ]);

                $new->assets()->sync($tile->assets->values()->mapWithKeys(fn (Asset $asset, int $order) => [$asset->id => GridPlanService::pivotRow($asset, $order)])->all());
            }

            $copy->update(['checklist_snapshot' => ($this->checklist)($copy)]);

            Log::info('Grid plan duplicated', [
                'grid_plan_id' => $copy->id,
                'source_grid_plan_id' => $plan->id,
                'org_id' => $plan->org_id,
                'user_id' => $user->id,
            ]);

            return $copy;
        });
    }
}
