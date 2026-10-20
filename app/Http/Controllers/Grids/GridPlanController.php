<?php

namespace App\Http\Controllers\Grids;

use App\Actions\Clients\BuildClientPageHeaderAction;
use App\Actions\Grids\CreateGridPlanAction;
use App\Actions\Grids\DeleteGridPlanAction;
use App\Actions\Grids\DuplicateGridPlanAction;
use App\Actions\Grids\GridPlanStatusTransitionMap;
use App\Actions\Grids\UpdateGridPlanAction;
use App\Enums\CampaignColour;
use App\Enums\CampaignStatus;
use App\Enums\GridPlanStatus;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Grids\StoreGridPlanRequest;
use App\Http\Requests\Grids\UpdateGridPlanRequest;
use App\Models\Asset;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\SocialAccount;
use App\Services\Grids\CompositeProfileFeedSource;
use App\Services\Grids\GridPlanService;
use App\Services\PostNotificationService;
use App\Support\Grids\GridLibrary;
use App\Support\Grids\GridPlanPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 18 — grid plans: the client page's Grid tab, the planner page (the editor) and the
 * plan's settings. Thin: authorization, a Form Request, an Action, a response.
 */
class GridPlanController extends Controller
{
    public function __construct(
        private readonly GridPlanPresenter $presenter,
        private readonly GridPlanService $grids,
    ) {}

    /**
     * The client page's Grid tab: the client's grid plans, newest first.
     */
    public function index(Request $request, Client $client, BuildClientPageHeaderAction $header): Response
    {
        $this->authorize('viewAny', [GridPlan::class, $client]);
        $user = $request->user();

        $plans = $client->gridPlans()
            ->with(['socialAccount', 'campaign', 'tiles.assets', 'tiles.coverAsset', 'tiles.post.targets'])
            ->orderByRaw("status = 'archived'")
            ->latest('updated_at')
            ->paginate(24);

        return Inertia::render('clients/tabs/grids', [
            ...$header($client, $user, 'grids'),
            'grids' => $plans->through(fn (GridPlan $plan) => GridLibrary::summary($plan, $this->grids)),
            'accounts' => $this->instagramAccounts($client),
            'campaignOptions' => $this->campaignOptions($client, null),
        ]);
    }

    public function store(StoreGridPlanRequest $request, Client $client, CreateGridPlanAction $action): RedirectResponse
    {
        $plan = $action($client, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Grid started. Add a few tiles.')]);

        return to_route('grids.edit', $plan);
    }

    /**
     * The grid planner (Step 18.5): the board, the tile inspector, the checklist, the preview and
     * the discussion.
     */
    public function edit(Request $request, GridPlan $grid, PostNotificationService $notifications): Response
    {
        $this->authorize('view', $grid);
        $user = $request->user();
        $this->grids->load($grid);

        $allowed = array_values(array_filter(
            GridPlanStatusTransitionMap::allowedFor($grid),
            fn (GridPlanStatus $to) => $user->can('transition', [$grid, $to]),
        ));

        return Inertia::render('grids/edit', [
            'grid' => $this->presenter->plan($grid),
            'client' => ['id' => $grid->client->id, 'name' => $grid->client->name],
            'allowedTransitions' => array_map(fn (GridPlanStatus $to) => [
                'value' => $to->value,
                'label' => $to->label(),
                'forward' => GridPlanStatusTransitionMap::isForward($grid->status, $to),
            ], $allowed),
            'can' => [
                'update' => $user->can('update', $grid),
                'comment' => $user->can('comment', $grid),
                'delete' => $user->can('delete', $grid),
                'produce' => $user->can('produce', $grid),
                'override_approval' => $user->can('overrideApproval', $grid),
                'use_ai' => $user->can('useAi', $grid),
                'create_asset' => $user->can('create', [Asset::class, $grid->client]),
            ],
            'campaignOptions' => $this->campaignOptions($grid->client, $grid->campaign_id),
            'placeholderColours' => array_map(fn (CampaignColour $colour) => ['value' => $colour->value, 'label' => $colour->label()], CampaignColour::cases()),
            'mentionableUsers' => $notifications->mentionableRecipients($grid, exclude: $user)
                ->map(fn ($mentionable) => ['id' => $mentionable->id, 'name' => $mentionable->name])
                ->values(),
            'minGapMinutes' => (int) config('grids.min_gap_minutes', 10),
            // Deferred: a Graph API call must never hold up the editor.
            'feed' => Inertia::defer(fn () => $this->presenter->feed($grid)),
            'comments' => Inertia::defer(fn () => $this->presenter->comments($grid), 'discussion'),
            'activity' => Inertia::defer(fn () => $this->presenter->activity($grid), 'discussion'),
            'studioImages' => Inertia::defer(fn () => GridLibrary::studioImages($grid), 'library'),
        ]);
    }

    public function update(UpdateGridPlanRequest $request, GridPlan $grid, UpdateGridPlanAction $action): RedirectResponse
    {
        $wasApproved = $grid->status->reopensOnEdit();
        $action($grid, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => $wasApproved && $grid->status === GridPlanStatus::Draft
            ? __('Saved. The grid is back in draft for a fresh review.')
            : __('Grid saved.')]);

        return to_route('grids.edit', $grid);
    }

    public function destroy(Request $request, GridPlan $grid, DeleteGridPlanAction $action): RedirectResponse
    {
        $this->authorize('delete', $grid);

        $clientId = $grid->client_id;
        $action($grid, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Grid deleted. Its posts are untouched.')]);

        return to_route('clients.grids.index', $clientId);
    }

    public function duplicate(Request $request, GridPlan $grid, DuplicateGridPlanAction $action): RedirectResponse
    {
        $this->authorize('view', $grid);
        $this->authorize('create', [GridPlan::class, $grid->client]);

        $copy = $action($grid, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Copied. This is a fresh draft.')]);

        return to_route('grids.edit', $copy);
    }

    /**
     * "Refresh" the live profile feed (rate-limited per account).
     */
    public function refreshFeed(GridPlan $grid, CompositeProfileFeedSource $feeds): RedirectResponse
    {
        $this->authorize('view', $grid);
        $grid->loadMissing('socialAccount');

        $refreshed = $feeds->refresh($grid->socialAccount);

        Inertia::flash('toast', $refreshed
            ? ['type' => 'success', 'message' => __('Profile refreshed.')]
            : ['type' => 'error', 'message' => __('Refreshed a lot just now. Try again in a little while.')]);

        return back();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function instagramAccounts(Client $client): array
    {
        return array_values($client->socialAccounts()
            ->where('platform', Platform::Instagram)
            ->orderBy('handle')
            ->get()
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'connection_status' => $account->connection_status->value,
            ])
            ->all());
    }

    /**
     * The client's open campaigns, plus the plan's own whatever its status.
     *
     * @return list<array{value: string, label: string}>
     */
    private function campaignOptions(Client $client, ?int $currentId): array
    {
        return array_values(Campaign::query()
            ->where('client_id', $client->id)
            ->where(fn ($query) => $query->whereIn('status', CampaignStatus::open())->orWhere('id', $currentId ?? 0))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Campaign $campaign) => ['value' => (string) $campaign->id, 'label' => $campaign->name])
            ->all());
    }
}
