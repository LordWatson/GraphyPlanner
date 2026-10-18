<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\ApplySuggestedPhasesAction;
use App\Actions\Campaigns\BulkAddDeliverablesAction;
use App\Actions\Campaigns\DeleteCampaignPhaseAction;
use App\Actions\Campaigns\ReorderCampaignPhasesAction;
use App\Actions\Campaigns\SaveCampaignDeliverableAction;
use App\Actions\Campaigns\SaveCampaignPhaseAction;
use App\Actions\Campaigns\StartPostFromDeliverableAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\BulkAddDeliverablesRequest;
use App\Http\Requests\Campaigns\SaveCampaignDeliverableRequest;
use App\Http\Requests\Campaigns\SaveCampaignPhaseRequest;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use App\Models\CampaignPhase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Step C-3.1 / C-3.2 — the Plan tab's writes: phases (CRUD, reorder, suggested split) and
 * deliverables (CRUD, bulk add, start post). Nested routes use scoped bindings, so a phase or
 * deliverable from another campaign is a 404.
 */
class CampaignPlanController extends Controller
{
    public function storePhase(SaveCampaignPhaseRequest $request, Campaign $campaign, SaveCampaignPhaseAction $action): RedirectResponse
    {
        $action($campaign, $request->validated(), $request->user());

        return back();
    }

    public function updatePhase(SaveCampaignPhaseRequest $request, Campaign $campaign, CampaignPhase $phase, SaveCampaignPhaseAction $action): RedirectResponse
    {
        $action($campaign, $request->validated(), $request->user(), $phase);

        return back();
    }

    public function destroyPhase(Request $request, Campaign $campaign, CampaignPhase $phase, DeleteCampaignPhaseAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);
        $action($phase, $request->user());

        return back();
    }

    public function reorderPhases(Request $request, Campaign $campaign, ReorderCampaignPhasesAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);

        $validated = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct', Rule::exists('campaign_phases', 'id')->where('campaign_id', $campaign->id)],
        ]);

        $action($campaign, array_values(array_map('intval', $validated['order'])), $request->user());

        return back();
    }

    public function suggestedPhases(Request $request, Campaign $campaign, ApplySuggestedPhasesAction $action): RedirectResponse
    {
        $this->authorize('update', $campaign);
        $phases = $action($campaign, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':count phases added. Rename or resize them any time.', ['count' => count($phases)])]);

        return back();
    }

    public function storeDeliverable(SaveCampaignDeliverableRequest $request, Campaign $campaign, SaveCampaignDeliverableAction $action): RedirectResponse
    {
        $action($campaign, $request->payload(), $request->user());

        return back();
    }

    public function bulkDeliverables(BulkAddDeliverablesRequest $request, Campaign $campaign, BulkAddDeliverablesAction $action): RedirectResponse
    {
        $created = $action(
            $campaign,
            $request->integer('per_week'),
            $request->platforms(),
            $request->phase(),
            $request->string('planned_time')->toString() ?: null,
            $request->string('title_prefix')->trim()->toString() ?: null,
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Nothing to add in that range.|{1} 1 deliverable added.|[2,*] :count deliverables added.', count($created), ['count' => count($created)])]);

        return back();
    }

    public function updateDeliverable(SaveCampaignDeliverableRequest $request, Campaign $campaign, CampaignDeliverable $deliverable, SaveCampaignDeliverableAction $action): RedirectResponse
    {
        $action($campaign, $request->payload(), $request->user(), $deliverable);

        return back();
    }

    public function destroyDeliverable(Request $request, Campaign $campaign, CampaignDeliverable $deliverable): RedirectResponse
    {
        $this->authorize('update', $campaign);

        // The post (if started) stays: it's real work. Only the planned slot goes.
        $deliverable->delete();

        Log::info('Campaign deliverable deleted', [
            'campaign_id' => $campaign->id,
            'deliverable_id' => $deliverable->id,
            'post_id' => $deliverable->post_id,
            'user_id' => $request->user()->id,
        ]);

        return back();
    }

    public function startDeliverable(Request $request, Campaign $campaign, CampaignDeliverable $deliverable, StartPostFromDeliverableAction $action): RedirectResponse
    {
        $this->authorize('produce', $campaign);

        $validated = $request->validate(['social_account_id' => ['nullable', 'integer']]);
        $post = $action($deliverable, $request->user(), isset($validated['social_account_id']) ? (int) $validated['social_account_id'] : null);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Post started. It’s an idea for now.')]);

        return to_route('posts.edit', $post);
    }
}
