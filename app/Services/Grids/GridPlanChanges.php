<?php

namespace App\Services\Grids;

use App\Actions\Grids\EvaluateGridChecklistAction;
use App\Enums\GridPlanStatus;
use App\Exceptions\Grids\StaleGridPlanException;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step 18.4 — what every grid write does around its change, inside the caller's transaction:
 * lock the plan row (so concurrent writes queue up and version checks are honest), then after the
 * change bump `version`, reopen an approved / changes-requested plan to `draft` ("Reopened by
 * edit"), and snapshot the checklist.
 */
class GridPlanChanges
{
    public function __construct(private readonly EvaluateGridChecklistAction $checklist = new EvaluateGridChecklistAction) {}

    /**
     * Lock and reload the plan; with `$expectedVersion`, refuse a stale write.
     */
    public function lock(GridPlan $plan, ?int $expectedVersion = null): GridPlan
    {
        /** @var GridPlan $locked */
        $locked = GridPlan::query()->withoutGlobalScopes()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

        if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
            Log::info('Stale grid write refused', [
                'grid_plan_id' => $locked->id,
                'expected_version' => $expectedVersion,
                'current_version' => $locked->version,
            ]);

            throw new StaleGridPlanException($locked->id, $expectedVersion, $locked->version);
        }

        return $locked;
    }

    /**
     * Record a change made by `$user` on a locked plan. `$affectsLook` is false for settings that
     * don't change what the client approved (the name, the note to the client, the live feed
     * toggle) — those never reopen an approved plan.
     */
    public function record(GridPlan $plan, ?User $user, string $event, bool $affectsLook = true): GridPlan
    {
        if ($affectsLook && $plan->status->reopensOnEdit()) {
            $from = $plan->status;

            $plan->forceFill([
                'status' => GridPlanStatus::Draft,
                'approved_at' => null,
                'approved_by' => null,
                'approved_layout_hash' => null,
            ]);

            $plan->activityLogs()->create([
                'user_id' => $user?->id,
                'from_status' => $from,
                'to_status' => GridPlanStatus::Draft,
                'note' => 'Reopened by edit',
            ]);

            Log::info('Grid plan reopened by edit', [
                'grid_plan_id' => $plan->id,
                'org_id' => $plan->org_id,
                'user_id' => $user?->id,
                'from_status' => $from->value,
            ]);
        }

        $plan->version++;
        $plan->save();

        $plan->unsetRelation('tiles');
        $plan->update(['checklist_snapshot' => ($this->checklist)($plan)]);

        Log::info('Grid plan changed', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user?->id,
            'event' => $event,
            'version' => $plan->version,
        ]);

        return $plan;
    }

    /**
     * Rewrite every tile's position to match `$orderedIds` (1…n). Positions are unique per plan
     * and SQLite can't defer that check, so tiles first move out of the way, then into place.
     *
     * @param  list<int>  $orderedIds
     */
    public static function writePositions(GridPlan $plan, array $orderedIds): void
    {
        GridTile::query()->where('grid_plan_id', $plan->id)->increment('position', 30000);

        foreach ($orderedIds as $index => $id) {
            GridTile::query()->where('grid_plan_id', $plan->id)->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    /**
     * The plan's tile ids in display order.
     *
     * @return list<int>
     */
    public static function orderedIds(GridPlan $plan): array
    {
        return array_values(array_map('intval', GridTile::query()
            ->where('grid_plan_id', $plan->id)
            ->orderBy('position')
            ->pluck('id')
            ->all()));
    }
}
