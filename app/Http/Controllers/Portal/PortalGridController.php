<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Grids\CreateGridCommentAction;
use App\Actions\Grids\DecideGridPlanAction;
use App\Enums\GridPlanStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grids\GridDecisionRequest;
use App\Http\Requests\Grids\StoreGridCommentRequest;
use App\Models\GridPlan;
use App\Services\Grids\GridPlanService;
use App\Services\PostNotificationService;
use App\Support\Grids\GridLibrary;
use App\Support\Grids\GridPlanPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 18.8 — the client's grids in the portal: only those sent to them
 * (`GridPlanStatus::clientVisible()`), only their own client (`EnsureClientPortalAccess`). The
 * same preview as the review link, with the same decision and tile-pinned comments.
 */
class PortalGridController extends Controller
{
    public function __construct(private readonly GridPlanPresenter $presenter) {}

    public function index(Request $request, GridPlanService $grids): Response
    {
        $user = $request->user();

        $plans = GridPlan::query()
            ->where('client_id', $user->client_id)
            ->whereIn('status', GridPlanStatus::clientVisible())
            ->with(['socialAccount', 'campaign', 'tiles.assets', 'tiles.coverAsset', 'tiles.post.targets'])
            ->orderByRaw("status = 'waiting_client' desc")
            ->latest('updated_at')
            ->get();

        return Inertia::render('portal/grids/index', [
            'grids' => $plans->map(function (GridPlan $plan) use ($grids) {
                $summary = GridLibrary::summary($plan, $grids);
                unset($summary['campaign']);

                return $summary;
            })->values(),
        ]);
    }

    public function show(Request $request, GridPlan $grid, PostNotificationService $notifications): Response
    {
        $user = $request->user();

        return Inertia::render('portal/grids/show', [
            'grid' => $this->presenter->plan($grid, forClient: true),
            'feed' => Inertia::defer(fn () => $this->presenter->feed($grid)),
            'comments' => $this->presenter->comments($grid, forClient: true),
            'can' => [
                'decide' => $user->can('transition', [$grid, GridPlanStatus::Approved]),
                'comment' => $user->can('comment', $grid),
            ],
            'mentionableUsers' => $notifications->mentionableRecipients($grid, exclude: $user)
                ->map(fn ($mentionable) => ['id' => $mentionable->id, 'name' => $mentionable->name])
                ->values(),
        ]);
    }

    public function decide(GridDecisionRequest $request, GridPlan $grid, DecideGridPlanAction $action): RedirectResponse
    {
        $action(
            $grid,
            $request->user(),
            GridPlanStatus::from((string) $request->validated('decision')),
            (int) $request->validated('version'),
            $request->validated('comment'),
            $request->flaggedTiles($grid),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $grid->status === GridPlanStatus::Approved
            ? __('Approved. Thank you — we’ll send the captions next.')
            : __('Thanks. We’ll make the changes and send it back.')]);

        return to_route('portal.grids.show', $grid);
    }

    public function comment(StoreGridCommentRequest $request, GridPlan $grid, CreateGridCommentAction $action): RedirectResponse
    {
        $tileId = $request->validated('grid_tile_id');

        $action($grid, $request->user(), (string) $request->validated('body'), false, $tileId !== null ? (int) $tileId : null);

        return to_route('portal.grids.show', $grid);
    }
}
