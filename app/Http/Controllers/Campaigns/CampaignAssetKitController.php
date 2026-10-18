<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\UpdateCampaignAssetKitAction;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Step C-4.1 — add library assets to the campaign's kit, or take one out (never deletes it).
 * Designers manage the kit too (`CampaignPolicy::produce`).
 */
class CampaignAssetKitController extends Controller
{
    public function store(Request $request, Campaign $campaign, UpdateCampaignAssetKitAction $action): RedirectResponse
    {
        $this->authorize('produce', $campaign);

        $validated = $request->validate([
            'asset_ids' => ['required', 'array', 'min:1', 'max:100'],
            'asset_ids.*' => ['integer', 'distinct', Rule::exists('assets', 'id')->where('client_id', $campaign->client_id)->whereNull('deleted_at')],
        ]);

        $count = $action->add($campaign, array_values(array_map('intval', $validated['asset_ids'])), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} 1 asset added to the kit.|[2,*] :count assets added to the kit.', $count, ['count' => $count])]);

        return back();
    }

    public function destroy(Request $request, Campaign $campaign, Asset $asset, UpdateCampaignAssetKitAction $action): RedirectResponse
    {
        $this->authorize('produce', $campaign);
        $action->remove($campaign, $asset, $request->user());

        return back();
    }
}
