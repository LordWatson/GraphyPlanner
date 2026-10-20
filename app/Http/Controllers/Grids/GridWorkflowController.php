<?php

namespace App\Http\Controllers\Grids;

use App\Actions\Grids\CreateGridCommentAction;
use App\Actions\Grids\TransitionGridPlanStatusAction;
use App\Enums\GridPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grids\OverrideGridApprovalRequest;
use App\Http\Requests\Grids\StoreGridCommentRequest;
use App\Http\Requests\Grids\TransitionGridPlanRequest;
use App\Models\GridPlan;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Step 18.7 — moving a grid through its workflow and talking about it, from the planner.
 */
class GridWorkflowController extends Controller
{
    public function transition(TransitionGridPlanRequest $request, GridPlan $grid, TransitionGridPlanStatusAction $action): RedirectResponse
    {
        $to = GridPlanStatus::from((string) $request->validated('to'));

        $action($grid, $to, $request->user(), $request->validated('note'));

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($to) {
            GridPlanStatus::WaitingClient => __('Sent to the client.'),
            GridPlanStatus::Approved => __('Grid approved. Turn the tiles into posts when you’re ready.'),
            default => __('Grid moved to :status.', ['status' => strtolower($to->label())]),
        }]);

        return to_route('grids.edit', $grid);
    }

    public function overrideApproval(OverrideGridApprovalRequest $request, GridPlan $grid, TransitionGridPlanStatusAction $action): RedirectResponse
    {
        $action->overrideApproval($grid, $request->user(), (string) $request->validated('reason'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Approved without the client. The reason is on the activity log.')]);

        return to_route('grids.edit', $grid);
    }

    public function comment(StoreGridCommentRequest $request, GridPlan $grid, CreateGridCommentAction $action): RedirectResponse
    {
        $tileId = $request->validated('grid_tile_id');

        $action($grid, $request->user(), (string) $request->validated('body'), (bool) $request->validated('internal_only'), $tileId !== null ? (int) $tileId : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment posted.')]);

        return back();
    }
}
