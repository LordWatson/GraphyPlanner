<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\CampaignStatusTransitionMap;
use App\Actions\Campaigns\PauseCampaignAction;
use App\Actions\Campaigns\ResumeCampaignAction;
use App\Actions\Campaigns\ShiftCampaignAction;
use App\Enums\CampaignStatus;
use App\Exceptions\Campaigns\InvalidCampaignTransitionException;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Steps C-6.1 / C-6.2 — steer a live campaign: pause (unschedules its scheduled posts), resume
 * (puts exactly those back), and shift every date by N days (preview first). Owner/Strategist.
 */
class CampaignRunController extends Controller
{
    public function pause(Request $request, Campaign $campaign, PauseCampaignAction $action): RedirectResponse
    {
        $this->authorize('transition', $campaign);
        $this->expectStatus($campaign, CampaignStatus::Paused);

        $paused = $action($campaign, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Paused. Nothing was scheduled.|{1} Paused. 1 post is off the schedule.|[2,*] Paused. :count posts are off the schedule.', count($paused), ['count' => count($paused)])]);

        return back();
    }

    public function resume(Request $request, Campaign $campaign, ResumeCampaignAction $action): RedirectResponse
    {
        $this->authorize('transition', $campaign);
        $this->expectStatus($campaign, CampaignStatus::Active);

        $validated = $request->validate(['mode' => ['required', Rule::in([ResumeCampaignAction::KEEP, ResumeCampaignAction::SHIFT, ResumeCampaignAction::NONE])]]);
        $result = $action($campaign, $request->user(), $validated['mode']);

        $message = trans_choice('{0} Back on. Nothing was rescheduled.|{1} Back on. 1 post rescheduled.|[2,*] Back on. :count posts rescheduled.', count($result['rescheduled']), ['count' => count($result['rescheduled'])]);

        if ($result['skipped'] !== []) {
            $message .= ' '.trans_choice('{1} 1 needs a new time.|[2,*] :count need a new time.', count($result['skipped']), ['count' => count($result['skipped'])]);
        }

        Inertia::flash('toast', ['type' => $result['skipped'] === [] ? 'success' : 'warning', 'message' => $message]);

        return back();
    }

    public function previewShift(Request $request, Campaign $campaign, ShiftCampaignAction $action): JsonResponse
    {
        $this->authorize('transition', $campaign);
        $validated = $request->validate(['days' => ['required', 'integer', 'between:-365,365', 'not_in:0']]);

        return response()->json($action->preview($campaign, (int) $validated['days']));
    }

    public function shift(Request $request, Campaign $campaign, ShiftCampaignAction $action): RedirectResponse
    {
        $this->authorize('transition', $campaign);
        $validated = $request->validate(['days' => ['required', 'integer', 'between:-365,365', 'not_in:0']]);

        $result = $action->apply($campaign, (int) $validated['days'], $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} Dates moved. No posts needed moving.|{1} Dates moved, with 1 post.|[2,*] Dates moved, with :count posts.', count($result['moved']), ['count' => count($result['moved'])])]);

        return back();
    }

    private function expectStatus(Campaign $campaign, CampaignStatus $to): void
    {
        if (! CampaignStatusTransitionMap::isAllowed($campaign->status, $to)) {
            throw new InvalidCampaignTransitionException($campaign->id, $campaign->status, $to);
        }
    }
}
