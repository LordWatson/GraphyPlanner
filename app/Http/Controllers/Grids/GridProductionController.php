<?php

namespace App\Http\Controllers\Grids;

use App\Actions\Grids\AddOutsidePostToGridAction;
use App\Actions\Grids\CreatePostsFromGridTilesAction;
use App\Actions\Grids\HoldGridPostsAction;
use App\Actions\Grids\MoveGridPostsAction;
use App\Actions\Grids\PlanGridScheduleAction;
use App\Actions\Grids\ResolveGridDriftAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grids\GridScheduleRequest;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Steps 18.9–18.11 — from an approved grid to a live profile: turn tiles into posts, move their
 * captions through review together, schedule the sequence, and keep the grid true afterwards
 * (drift fixes, outside posts, "Hold the rest"). Every move is a person's click.
 */
class GridProductionController extends Controller
{
    public function createPosts(Request $request, GridPlan $grid, CreatePostsFromGridTilesAction $action): RedirectResponse
    {
        $this->authorize('produce', $grid);
        $validated = $request->validate([
            'tile_ids' => ['nullable', 'array'],
            'tile_ids.*' => ['integer', Rule::exists('grid_tiles', 'id')->where('grid_plan_id', $grid->id)],
        ]);

        $result = $action($grid, $request->user(), isset($validated['tile_ids']) ? array_values(array_map('intval', $validated['tile_ids'])) : null);
        $created = count($result['created']);

        Inertia::flash('gridReport', ['title' => 'Posts made', 'skipped' => $result['skipped']]);
        Inertia::flash('toast', [
            'type' => $created === 0 && $result['skipped'] !== [] ? 'error' : 'success',
            'message' => trans_choice('{0} No new posts — every tile already has one.|{1} 1 draft post made. Its caption still needs approval.|[2,*] :count draft posts made. Their captions still need approval.', $created, ['count' => $created])
                .($result['skipped'] !== [] ? ' '.trans_choice('{1} 1 tile was skipped.|[2,*] :count tiles were skipped.', count($result['skipped']), ['count' => count($result['skipped'])]) : ''),
        ]);

        return to_route('grids.edit', $grid);
    }

    public function bulkMove(Request $request, GridPlan $grid, MoveGridPostsAction $action): RedirectResponse
    {
        $this->authorize('produce', $grid);
        $validated = $request->validate(['action' => ['required', Rule::in(MoveGridPostsAction::ACTIONS)]]);

        return $this->moved($grid, $action($grid, $request->user(), (string) $validated['action']), (string) $validated['action']);
    }

    public function scheduleApproved(Request $request, GridPlan $grid, MoveGridPostsAction $action): RedirectResponse
    {
        $this->authorize('produce', $grid);

        return $this->moved($grid, $action($grid, $request->user(), MoveGridPostsAction::SCHEDULE), MoveGridPostsAction::SCHEDULE);
    }

    /**
     * Step 18.10 — what a cadence would do. Writes nothing.
     */
    public function previewSchedule(GridScheduleRequest $request, GridPlan $grid, PlanGridScheduleAction $action): JsonResponse
    {
        return response()->json(['rows' => $action->preview($grid, $request->schedule())]);
    }

    public function applySchedule(GridScheduleRequest $request, GridPlan $grid, PlanGridScheduleAction $action): RedirectResponse
    {
        $result = $action->apply($grid, $request->user(), $request->schedule());

        Inertia::flash('gridReport', ['title' => 'Dates set', 'skipped' => $result['skipped']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} 1 tile dated.|[2,*] :count tiles dated, in publish order.', max(1, $result['tiles']), ['count' => $result['tiles']])]);

        return to_route('grids.edit', $grid);
    }

    /**
     * Step 18.11 — "Hold the rest" when the grid's order is at risk.
     */
    public function holdRest(Request $request, GridPlan $grid, HoldGridPostsAction $action): RedirectResponse
    {
        $this->authorize('produce', $grid);

        $result = $action($grid, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Nothing needed holding.|{1} Held 1 post. It’s back to approved.|[2,*] Held :count posts. They’re back to approved.', count($result['done']), ['count' => count($result['done'])])]);

        return back();
    }

    public function syncTileFromPost(Request $request, GridPlan $grid, GridTile $tile, ResolveGridDriftAction $action): RedirectResponse
    {
        $this->authorize('update', $grid);

        $action->tileFromPost($tile, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tile updated from the post. The grid is back in draft for a fresh review.')]);

        return to_route('grids.edit', ['grid' => $grid, 'tile' => $tile->id]);
    }

    public function restorePost(Request $request, GridPlan $grid, GridTile $tile, ResolveGridDriftAction $action): RedirectResponse
    {
        $this->authorize('produce', $grid);

        $action->postFromTile($tile, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The post matches the approved grid again.')]);

        return to_route('grids.edit', ['grid' => $grid, 'tile' => $tile->id]);
    }

    public function addOutsidePost(Request $request, GridPlan $grid, Post $post, AddOutsidePostToGridAction $action): RedirectResponse
    {
        $this->authorize('update', $grid);
        abort_unless($post->client_id === $grid->client_id, 404);

        $tile = $action($grid, $post, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Added to the grid as tile :position.', ['position' => $tile->position])]);

        return to_route('grids.edit', ['grid' => $grid, 'tile' => $tile->id]);
    }

    /**
     * @param  array{done: list<int>, skipped: list<array<string, mixed>>}  $result
     */
    private function moved(GridPlan $grid, array $result, string $action): RedirectResponse
    {
        $done = count($result['done']);

        Inertia::flash('gridReport', ['title' => match ($action) {
            MoveGridPostsAction::SCHEDULE => 'Not scheduled',
            MoveGridPostsAction::SEND_TO_CLIENT => 'Not sent',
            default => 'Not moved',
        }, 'skipped' => $result['skipped']]);
        Inertia::flash('toast', ['type' => $done === 0 ? 'error' : 'success', 'message' => match ($action) {
            MoveGridPostsAction::SCHEDULE => trans_choice('{0} Nothing was ready to schedule.|{1} Scheduled 1 post.|[2,*] Scheduled :count posts, in publish order.', $done, ['count' => $done]),
            MoveGridPostsAction::SEND_TO_CLIENT => trans_choice('{0} Nothing was ready to send.|{1} Sent 1 caption to the client.|[2,*] Sent :count captions to the client in one review.', $done, ['count' => $done]),
            default => trans_choice('{0} Nothing was ready to submit.|{1} Submitted 1 post for review.|[2,*] Submitted :count posts for review.', $done, ['count' => $done]),
        }]);

        return to_route('grids.edit', $grid);
    }
}
