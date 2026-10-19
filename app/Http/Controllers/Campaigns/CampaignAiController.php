<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\AcceptCampaignPlanAction;
use App\Actions\Campaigns\DraftCampaignBriefAction;
use App\Actions\Campaigns\DraftCampaignWrapAction;
use App\Actions\Campaigns\StartCampaignPlanAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\AcceptCampaignPlanRequest;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignPlanRuns;
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

    /**
     * Planning can outlast a web request (a reasoning model takes 60s+), so this only queues the
     * run and answers 202 with its id; the planner polls `planStatus` for the suggestions.
     */
    public function suggestPlan(Request $request, Campaign $campaign, StartCampaignPlanAction $action): JsonResponse
    {
        $this->guard($request, $campaign);

        return response()->json(['run' => $action($campaign, $request->user())], 202);
    }

    public function planStatus(Request $request, Campaign $campaign, string $run, CampaignPlanRuns $runs): JsonResponse
    {
        $this->guard($request, $campaign);

        $status = $runs->find($campaign, $request->user(), $run);
        abort_if($status === null, 404);

        return response()->json($status);
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
