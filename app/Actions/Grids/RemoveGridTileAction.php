<?php

namespace App\Actions\Grids;

use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RemoveGridTileAction
{
    public function __construct(private readonly GridPlanChanges $changes = new GridPlanChanges) {}

    /**
     * Step 18.4 — remove a tile and close the gap. A post made from it stays (it's a real post now)
     * and simply loses its grid link; its media stays in the library.
     */
    public function __invoke(GridTile $tile, User $user): GridPlan
    {
        return DB::transaction(function () use ($tile, $user) {
            $plan = $this->changes->lock($tile->plan);
            $tileId = $tile->id;
            $postId = $tile->post_id;

            $tile->delete();
            GridPlanChanges::writePositions($plan, GridPlanChanges::orderedIds($plan));

            Log::info('Grid tile removed', [
                'grid_plan_id' => $plan->id,
                'grid_tile_id' => $tileId,
                'post_id' => $postId,
                'user_id' => $user->id,
            ]);

            return $this->changes->record($plan, $user, 'tile_removed');
        });
    }
}
