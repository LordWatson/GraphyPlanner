<?php

namespace App\Http\Controllers\Business;

use App\Actions\Grids\CreateGridPlanAction;
use App\Contracts\Entitlements;
use App\Enums\CampaignStatus;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grids\StoreGridPlanRequest;
use App\Models\Campaign;
use App\Models\GridPlan;
use App\Models\SocialAccount;
use App\Services\Grids\GridPlanService;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\Grids\GridLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-1.2 — the Instagram grid planner in the business/creator shell. Brand-implicit: the hub
 * and "New grid" resolve the one brand from {@see CurrentWorkspace} and call the same
 * {@see CreateGridPlanAction}/{@see GridPlanService} the agency uses. The planner itself is the
 * shared `grids.edit` surface (brand-agnostic), so there is nothing to fork there.
 */
class GridController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, GridPlanService $grids): Response
    {
        $brand = $workspace->brand();
        $user = $request->user();
        $organization = $brand->organization;
        $entitled = $organization !== null
            && app(Entitlements::class)->allows($organization, Feature::GridPlanner);

        $this->authorize('viewAny', [GridPlan::class, $brand]);

        $plans = GridPlan::query()
            ->where('client_id', $brand->id)
            ->with(['socialAccount', 'campaign', 'tiles.assets', 'tiles.coverAsset', 'tiles.post.targets'])
            ->orderByRaw("status = 'archived'")
            ->latest('updated_at')
            ->paginate(24)
            ->withQueryString();

        $accounts = $brand->socialAccounts()
            ->where('platform', Platform::Instagram)
            ->orderBy('handle')
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'connection_status' => $account->connection_status->value,
                'client_id' => $brand->id,
            ])
            ->values();

        return Inertia::render('business/grids/index', [
            'grids' => $plans->through(fn (GridPlan $plan) => GridLibrary::summary($plan, $grids)),
            'brandId' => $brand->id,
            'accounts' => $accounts,
            'campaignOptions' => Campaign::query()
                ->where('client_id', $brand->id)
                ->whereIn('status', CampaignStatus::open())
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Campaign $campaign) => ['value' => (string) $campaign->id, 'label' => $campaign->name, 'client_id' => $campaign->client_id])
                ->values(),
            'hasInstagram' => $accounts->isNotEmpty(),
            // Step CG-2.2: the grid planner is a plan gate. Free business workspaces can see the hub
            // but not create plans; the UI shows an upgrade panel keyed off `entitled`. The write
            // path is enforced in CreateGridPlanAction itself.
            'entitled' => $entitled,
            'can' => [
                'create' => $accounts->isNotEmpty()
                    && $entitled
                    && $user->can('create', [GridPlan::class, $brand]),
            ],
        ]);
    }

    public function store(StoreGridPlanRequest $request, CurrentWorkspace $workspace, CreateGridPlanAction $action): RedirectResponse
    {
        $plan = $action($workspace->brand(), $request->user(), $request->validated());

        return to_route('grids.edit', $plan);
    }
}
