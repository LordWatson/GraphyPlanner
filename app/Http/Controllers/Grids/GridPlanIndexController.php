<?php

namespace App\Http\Controllers\Grids;

use App\Enums\CampaignStatus;
use App\Enums\GridPlanStatus;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\SocialAccount;
use App\Services\Grids\GridPlanService;
use App\Support\Grids\GridLibrary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 18 — `GET /grids`: every Instagram grid plan across the organization's clients, newest
 * first, filterable by client and status, with "New grid" (pick the client's Instagram account).
 * A section of chapter 04 Clients, like the campaigns hub. Client contacts go to the portal.
 */
class GridPlanIndexController extends Controller
{
    public function __invoke(Request $request, GridPlanService $grids): Response
    {
        $this->authorize('viewHub', GridPlan::class);
        $user = $request->user();

        $clientId = $request->integer('client_id') ?: null;
        $status = GridPlanStatus::tryFrom((string) $request->query('status'));

        $plans = GridPlan::query()
            ->whereIn('client_id', Client::query()->select('id'))
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->with(['client:id,name', 'socialAccount', 'campaign', 'tiles.assets', 'tiles.coverAsset', 'tiles.post.targets'])
            ->orderByRaw("status = 'archived'")
            ->latest('updated_at')
            ->paginate(24)
            ->withQueryString();

        $accounts = SocialAccount::query()
            ->where('platform', Platform::Instagram)
            ->whereIn('client_id', Client::query()->select('id'))
            ->with('client:id,org_id,name')
            ->orderBy('handle')
            ->get()
            ->filter(fn (SocialAccount $account) => $user->can('create', [GridPlan::class, $account->client]))
            ->map(fn (SocialAccount $account) => [
                'id' => $account->id,
                'handle' => $account->handle,
                'display_name' => $account->display_name,
                'connection_status' => $account->connection_status->value,
                'client_id' => $account->client_id,
                'client_name' => $account->client->name,
            ])
            ->values();

        return Inertia::render('grids/index', [
            'grids' => $plans->through(fn (GridPlan $plan) => [
                ...GridLibrary::summary($plan, $grids),
                'client' => ['id' => $plan->client_id, 'name' => $plan->client->name],
            ]),
            'filters' => ['client_id' => $clientId, 'status' => $status?->value],
            'filterOptions' => [
                'clients' => Client::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Client $client) => ['value' => (string) $client->id, 'label' => $client->name])
                    ->values(),
                'statuses' => array_map(fn (GridPlanStatus $status) => ['value' => $status->value, 'label' => $status->label()], GridPlanStatus::cases()),
            ],
            'accounts' => $accounts,
            'campaignOptions' => Campaign::query()
                ->whereIn('status', CampaignStatus::open())
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Campaign $campaign) => ['value' => (string) $campaign->id, 'label' => $campaign->name, 'client_id' => $campaign->client_id])
                ->values(),
            'can' => ['create' => $accounts->isNotEmpty()],
        ]);
    }
}
