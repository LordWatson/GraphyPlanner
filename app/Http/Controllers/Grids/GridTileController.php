<?php

namespace App\Http\Controllers\Grids;

use App\Actions\Grids\AddGridTilesAction;
use App\Actions\Grids\RemoveGridTileAction;
use App\Actions\Grids\ReorderGridTilesAction;
use App\Actions\Grids\SplitAssetIntoGridTilesAction;
use App\Actions\Grids\UpdateGridTileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grids\ReorderGridTilesRequest;
use App\Http\Requests\Grids\SplitGridAssetRequest;
use App\Http\Requests\Grids\StoreGridTilesRequest;
use App\Http\Requests\Grids\UpdateGridTileRequest;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Phase 18 — a grid's tiles: add, edit, reorder, remove and split. Every write goes through an
 * Action that bumps the grid's version and re-runs its checklist.
 */
class GridTileController extends Controller
{
    public function store(StoreGridTilesRequest $request, GridPlan $grid, AddGridTilesAction $action): RedirectResponse
    {
        $tiles = $action($grid, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} Tile added.|[2,*] :count tiles added.', $tiles->count(), ['count' => $tiles->count()])]);

        return to_route('grids.edit', ['grid' => $grid, 'tile' => $tiles->first()?->id]);
    }

    public function update(UpdateGridTileRequest $request, GridPlan $grid, GridTile $tile, UpdateGridTileAction $action): RedirectResponse
    {
        $action($tile, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tile saved.')]);

        return to_route('grids.edit', ['grid' => $grid, 'tile' => $tile->id]);
    }

    public function destroy(Request $request, GridPlan $grid, GridTile $tile, RemoveGridTileAction $action): RedirectResponse
    {
        $this->authorize('update', $grid);

        $action($tile, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tile removed.')]);

        return to_route('grids.edit', $grid);
    }

    /**
     * Saves straight away (the planner reorders optimistically). A stale `expected_version`
     * answers 409 to JSON callers, or redirects back with a toast so Inertia reloads the latest.
     */
    public function reorder(ReorderGridTilesRequest $request, GridPlan $grid, ReorderGridTilesAction $action): RedirectResponse|JsonResponse
    {
        $action($grid, $request->user(), array_values(array_map('intval', (array) $request->validated('tile_ids'))), (int) $request->validated('expected_version'));

        if ($request->expectsJson()) {
            return response()->json(['version' => $grid->version]);
        }

        return back();
    }

    public function split(SplitGridAssetRequest $request, GridPlan $grid, SplitAssetIntoGridTilesAction $action): RedirectResponse
    {
        $source = Asset::query()->findOrFail((int) $request->validated('asset_id'));
        $atPosition = $request->validated('at_position');

        $tiles = $action($grid, $request->user(), $source, (string) $request->validated('layout'), $atPosition !== null ? (int) $atPosition : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Split into :count tiles. They move together.', ['count' => $tiles->count()])]);

        return to_route('grids.edit', $grid);
    }
}
