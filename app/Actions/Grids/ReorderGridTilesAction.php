<?php

namespace App\Actions\Grids;

use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderGridTilesAction
{
    public function __construct(private readonly GridPlanChanges $changes = new GridPlanChanges) {}

    /**
     * Step 18.4 — put the grid in a new order: the full list of tile ids, top-left first, plus the
     * `version` the planner saw. A stale version means someone else changed the grid meanwhile —
     * nothing moves (`StaleGridPlanException`, a 409). The list must hold exactly this plan's
     * tiles, and a split group's tiles must stay together in their own order (they move as one
     * block).
     *
     * @param  list<int>  $orderedIds
     */
    public function __invoke(GridPlan $plan, User $user, array $orderedIds, int $expectedVersion): GridPlan
    {
        $updated = DB::transaction(function () use ($plan, $user, $orderedIds, $expectedVersion) {
            $locked = $this->changes->lock($plan, $expectedVersion);
            $current = GridPlanChanges::orderedIds($locked);

            $sortedNew = $orderedIds;
            $sortedCurrent = $current;
            sort($sortedNew);
            sort($sortedCurrent);

            if ($sortedNew !== $sortedCurrent) {
                throw ValidationException::withMessages(['tile_ids' => __('Send every tile in this grid exactly once.')]);
            }

            $this->assertSplitsStayTogether($locked, $current, $orderedIds);

            if ($orderedIds === $current) {
                return $locked;
            }

            GridPlanChanges::writePositions($locked, $orderedIds);

            return $this->changes->record($locked, $user, 'tiles_reordered');
        });

        $plan->setRawAttributes($updated->getAttributes(), true);

        return $plan;
    }

    /**
     * @param  list<int>  $current
     * @param  list<int>  $ordered
     */
    private function assertSplitsStayTogether(GridPlan $plan, array $current, array $ordered): void
    {
        $groups = GridTile::query()
            ->where('grid_plan_id', $plan->id)
            ->whereNotNull('split_group_id')
            ->pluck('split_group_id', 'id');

        foreach ($groups->unique() as $group) {
            $members = $groups->filter(fn ($value) => $value === $group)->keys()->map(fn ($id) => (int) $id)->all();
            $before = array_values(array_filter($current, fn (int $id) => in_array($id, $members, true)));
            $positions = array_keys(array_filter($ordered, fn (int $id) => in_array($id, $members, true)));
            $after = array_map(fn (int $index) => $ordered[$index], $positions);

            if ($positions !== [] && (max($positions) - min($positions) + 1 !== count($positions) || $after !== $before)) {
                throw ValidationException::withMessages(['tile_ids' => __('Split tiles move together. Move the whole split, or remove it first.')]);
            }
        }
    }
}
