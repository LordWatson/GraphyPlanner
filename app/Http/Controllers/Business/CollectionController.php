<?php

namespace App\Http\Controllers\Business;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Enums\CampaignColour;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Models\Campaign;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 — the business shell's "Collections": campaigns, renamed for a brand that groups its
 * own posts (a product launch, a seasonal push) with no agency machinery. Reuses the campaign
 * Actions; each collection links to the posts list filtered to it, keeping business users out of
 * the agency campaign workspace.
 */
class CollectionController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace): Response
    {
        $brand = $workspace->brand();

        $this->authorize('viewAny', [Campaign::class, $brand]);

        return Inertia::render('business/collections', [
            'collections' => $brand->campaigns()
                ->withCount('posts')
                ->orderByRaw("status = 'archived'")
                ->orderBy('name')
                ->get()
                ->map(fn (Campaign $campaign) => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'colour' => $campaign->colour?->value,
                    'status' => $campaign->status->value,
                    'status_label' => $campaign->status->label(),
                    'posts_count' => $campaign->posts_count,
                ]),
            'colours' => array_map(
                fn (CampaignColour $colour) => ['value' => $colour->value, 'label' => $colour->label()],
                CampaignColour::cases(),
            ),
            'can' => [
                'create' => $request->user()->can('create', [Campaign::class, $brand]),
            ],
        ]);
    }

    public function store(StoreCampaignRequest $request, CurrentWorkspace $workspace, CreateCampaignAction $action): RedirectResponse
    {
        $action($workspace->brand(), $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Collection created.')]);

        return to_route('business.collections.index');
    }
}
