<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\BulkUpdateCampaignPostsAction;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Step C-4.6 — bulk actions on a selection of the campaign's posts (Approvals tab, Board). Answers
 * JSON with what moved and what was skipped and why, so the UI can say so precisely.
 */
class CampaignPostBulkController extends Controller
{
    public function __invoke(Request $request, Campaign $campaign, BulkUpdateCampaignPostsAction $action): JsonResponse
    {
        $this->authorize('produce', $campaign);

        $validated = $request->validate([
            'action' => ['required', Rule::in(BulkUpdateCampaignPostsAction::ACTIONS)],
            'post_ids' => ['required', 'array', 'min:1', 'max:200'],
            'post_ids.*' => ['integer', 'distinct'],
            'value' => [
                'nullable', 'integer',
                Rule::when($request->input('action') === BulkUpdateCampaignPostsAction::PHASE, [Rule::exists('campaign_phases', 'id')->where('campaign_id', $campaign->id)]),
                Rule::when($request->input('action') === BulkUpdateCampaignPostsAction::ASSIGN, [Rule::exists('users', 'id')->where('org_id', $campaign->org_id)->whereIn('role', ['owner', 'strategist', 'designer'])]),
            ],
        ]);

        // Moving work between phases or people is planning: Owner/Strategist only.
        if (in_array($validated['action'], [BulkUpdateCampaignPostsAction::PHASE, BulkUpdateCampaignPostsAction::ASSIGN], true)) {
            $this->authorize('update', $campaign);
        }

        return response()->json($action(
            $campaign,
            $request->user(),
            $validated['action'],
            array_values(array_map('intval', $validated['post_ids'])),
            isset($validated['value']) ? (int) $validated['value'] : null,
        ));
    }
}
