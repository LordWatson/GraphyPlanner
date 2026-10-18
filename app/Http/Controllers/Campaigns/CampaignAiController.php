<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\AcceptCampaignPlanAction;
use App\Actions\Campaigns\DraftCampaignBriefAction;
use App\Actions\Campaigns\DraftCampaignWrapAction;
use App\Actions\Campaigns\PlanCampaignAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\AcceptCampaignPlanRequest;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Steps C-2.2, C-3.3, C-7.3 — the campaign's AI suggestions. Every endpoint is 404 while AI is
 * off (same as `AiController`), Owner/Strategist only (`CampaignPolicy::update`), POST and
 * throttled (`throttle:ai`), and returns a suggestion as JSON — nothing is persisted here except
 * the plan a person explicitly accepts.
 */
class CampaignAiController extends Controller
{
    public function draftBrief(Request $request, Campaign $campaign, DraftCampaignBriefAction $action): JsonResponse
    {
        $this->guard($request, $campaign);

        return response()->json(['draft' => $action($campaign, $request->user())]);
    }

    public function suggestPlan(Request $request, Campaign $campaign, PlanCampaignAction $action): JsonResponse
    {
        $this->guard($request, $campaign);

        return response()->json(['suggestions' => $action($campaign, $request->user())]);
    }

    public function acceptPlan(AcceptCampaignPlanRequest $request, Campaign $campaign, AcceptCampaignPlanAction $action): RedirectResponse
    {
        /** @var list<array<string, mixed>> $items */
        $items = array_values($request->validated('items'));
        $created = $action($campaign, $items, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} 1 deliverable added to the plan.|[2,*] :count deliverables added to the plan.', count($created), ['count' => count($created)])]);

        return back();
    }

    public function draftWrap(Request $request, Campaign $campaign, DraftCampaignWrapAction $action): JsonResponse
    {
        $this->guard($request, $campaign);

        return response()->json(['draft' => $action($campaign, $request->user())]);
    }

    private function guard(Request $request, Campaign $campaign): void
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('update', $campaign);
    }
}
