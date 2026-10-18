<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\CreateCampaignShareLinkAction;
use App\Actions\Campaigns\RevokeCampaignShareLinkAction;
use App\Actions\Campaigns\ShowCampaignShareLinkAction;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaigns enhancement C-5.4 — preview links: Owner/Strategist create and revoke them; anyone
 * holding a valid token sees the read-only page (`share/campaign`).
 */
class CampaignShareLinkController extends Controller
{
    public function store(Request $request, Campaign $campaign, CreateCampaignShareLinkAction $create): JsonResponse
    {
        $this->authorize('update', $campaign);
        $validated = $request->validate(['days' => ['nullable', 'integer', 'between:1,'.CampaignShareLink::MAX_DAYS]]);

        ['token' => $token, 'link' => $link] = $create($campaign, $request->user(), (int) ($validated['days'] ?? CampaignShareLink::DEFAULT_DAYS));

        return response()->json([
            'id' => $link->id,
            'url' => route('campaign-share.show', $token),
            'expires_at' => $link->expires_at->toIso8601String(),
        ], 201);
    }

    public function destroy(Request $request, Campaign $campaign, CampaignShareLink $shareLink, RevokeCampaignShareLinkAction $revoke): RedirectResponse
    {
        $this->authorize('update', $campaign);
        $revoke($shareLink, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Link switched off. Anyone holding it now sees nothing.')]);

        return back();
    }

    public function show(string $token, ShowCampaignShareLinkAction $show): Response
    {
        return Inertia::render('share/campaign', $show($token));
    }
}
