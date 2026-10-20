<?php

namespace App\Actions\Grids;

use App\Enums\GridPlanStatus;
use App\Models\Asset;
use App\Models\GridPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneGridPlansAction
{
    /**
     * Step 18.13 — soft-delete archived grid plans untouched for `grids.prune_archived_after_months`
     * (12 by default), and soft-delete the split slices they made that nothing else uses. A slice
     * any post still uses (or another live grid holds) is kept. Logs counts only.
     *
     * @return array{plans: int, assets: int}
     */
    public function __invoke(?int $months = null): array
    {
        $months ??= max(1, (int) config('grids.prune_archived_after_months', 12));
        $cutoff = now()->subMonths($months);

        $plans = GridPlan::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', GridPlanStatus::Archived)
            ->where('updated_at', '<=', $cutoff)
            ->pluck('id');

        if ($plans->isEmpty()) {
            Log::info('Grid plans pruned', ['plans' => 0, 'assets' => 0]);

            return ['plans' => 0, 'assets' => 0];
        }

        $assets = 0;

        DB::transaction(function () use ($plans, &$assets) {
            $sliceIds = DB::table('grid_tile_assets')
                ->join('grid_tiles', 'grid_tiles.id', '=', 'grid_tile_assets.grid_tile_id')
                ->join('assets', 'assets.id', '=', 'grid_tile_assets.asset_id')
                ->whereIn('grid_tiles.grid_plan_id', $plans)
                ->whereNotNull('assets.source_asset_id')
                ->pluck('assets.id')
                ->unique();

            GridPlan::query()->withoutGlobalScopes()->whereKey($plans)->get()->each->delete();

            $unused = Asset::query()
                ->withoutGlobalScopes()
                ->whereKey($sliceIds)
                ->whereDoesntHave('posts')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                    ->from('grid_tile_assets')
                    ->join('grid_tiles', 'grid_tiles.id', '=', 'grid_tile_assets.grid_tile_id')
                    ->join('grid_plans', 'grid_plans.id', '=', 'grid_tiles.grid_plan_id')
                    ->whereColumn('grid_tile_assets.asset_id', 'assets.id')
                    ->whereNull('grid_plans.deleted_at'))
                ->get();

            $unused->each->delete();
            $assets = $unused->count();
        });

        Log::info('Grid plans pruned', ['plans' => $plans->count(), 'assets' => $assets]);

        return ['plans' => $plans->count(), 'assets' => $assets];
    }
}
