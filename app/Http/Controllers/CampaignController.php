<?php

namespace App\Http\Controllers;

use App\Actions\Campaigns\CreateCampaignAction;
use App\Actions\Campaigns\PauseCampaignAction;
use App\Actions\Campaigns\ResumeCampaignAction;
use App\Actions\Campaigns\TransitionCampaignStatusAction;
use App\Actions\Campaigns\UpdateCampaignAction;
use App\Enums\CampaignBillingType;
use App\Enums\CampaignColour;
use App\Enums\CampaignObjective;
use App\Enums\CampaignStatus;
use App\Enums\Role;
use App\Http\Requests\Campaigns\StoreCampaignRequest;
use App\Http\Requests\Campaigns\TransitionCampaignRequest;
use App\Http\Requests\Campaigns\UpdateCampaignRequest;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\User;
use App\Support\Campaigns\CampaignClock;
use App\Support\Campaigns\CampaignPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaign create / settings / delete, and status transitions (Step C-0.3). The workspace tabs
 * (`campaigns.show` and its siblings) live in `Campaigns\CampaignWorkspaceController`.
 */
class CampaignController extends Controller
{
    /**
     * Create a new campaign for the given client and open its workspace.
     */
    public function store(StoreCampaignRequest $request, Client $client, CreateCampaignAction $action): RedirectResponse
    {
        $campaign = $action($client, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign created. Next, the brief.')]);

        return to_route('campaigns.show', $campaign);
    }

    /**
     * The campaign settings form (name, dates, owner, objective, colour, defaults).
     */
    public function edit(Request $request, Campaign $campaign): Response
    {
        $this->authorize('update', $campaign);
        $campaign->loadMissing(['client', 'owner:id,name', 'organization']);

        return Inertia::render('campaigns/edit', [
            'campaign' => CampaignPresenter::forStaff($campaign, $request->user(), CampaignClock::today($campaign)),
            'options' => self::formOptions($campaign->org_id),
        ]);
    }

    /**
     * Update an existing campaign's details.
     */
    public function update(UpdateCampaignRequest $request, Campaign $campaign, UpdateCampaignAction $action): RedirectResponse
    {
        $action($campaign, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign saved.')]);

        return to_route('campaigns.show', $campaign);
    }

    /**
     * Step C-0.3: move the campaign along its state machine.
     */
    public function transition(
        TransitionCampaignRequest $request,
        Campaign $campaign,
        TransitionCampaignStatusAction $action,
        PauseCampaignAction $pause,
        ResumeCampaignAction $resume,
    ): RedirectResponse {
        $to = $request->target();

        // Step C-6.1: pausing and resuming carry their side effects (unschedule / reschedule)
        // whichever button the move came from.
        match (true) {
            $to === CampaignStatus::Paused => $pause($campaign, $request->user()),
            $campaign->status === CampaignStatus::Paused && $to === CampaignStatus::Active => $resume($campaign, $request->user(), ResumeCampaignAction::KEEP),
            default => $action($campaign, $to, $request->user()),
        };

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Campaign is now :status.', ['status' => strtolower($to->label())])]);

        return back();
    }

    /**
     * Delete a campaign (soft delete).
     */
    public function destroy(Request $request, Campaign $campaign): RedirectResponse
    {
        $this->authorize('delete', $campaign);

        $clientId = $campaign->client_id;
        $campaign->delete();
        $campaign->recordActivity('campaign.deleted', $request->user());

        return to_route('clients.campaigns.index', $clientId);
    }

    /**
     * The select options the create and settings forms share.
     *
     * @return array<string, mixed>
     */
    public static function formOptions(int $orgId): array
    {
        return [
            'owners' => User::query()
                ->where('org_id', $orgId)
                ->whereIn('role', [Role::Owner, Role::Strategist, Role::Designer, Role::Viewer])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
                ->all(),
            'objectives' => array_map(fn (CampaignObjective $objective) => ['value' => $objective->value, 'label' => $objective->label()], CampaignObjective::cases()),
            'colours' => array_map(fn (CampaignColour $colour) => ['value' => $colour->value, 'label' => $colour->label()], CampaignColour::cases()),
            'platforms' => CampaignPresenter::platformOptions(),
            'billingTypes' => array_map(fn (CampaignBillingType $type) => ['value' => $type->value, 'label' => $type->label()], CampaignBillingType::cases()),
        ];
    }
}
