<?php

namespace App\Actions\Grids;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Enums\GridPlanStatus;
use App\Models\Client;
use App\Models\GridPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateGridPlanAction
{
    public function __construct(private readonly EvaluateGridChecklistAction $checklist = new EvaluateGridChecklistAction) {}

    /**
     * Step 18.4 — start a grid plan for one of the client's Instagram accounts (validated by
     * `StoreGridPlanRequest`), in `draft` at version 1 with its first checklist snapshot.
     *
     * Step CG-2.2: the grid planner is a plan gate. Agency plans always grant it; a business
     * workspace on Free gets a `PlanLimitReachedException` (friendly upgrade prompt).
     *
     * @param  array<string, mixed>  $data  `name`, `social_account_id`, and optionally `campaign_id`, `pinned_count`, `include_live_feed`, `review_message`
     */
    public function __invoke(Client $client, User $user, array $data): GridPlan
    {
        if ($client->organization !== null) {
            app(Entitlements::class)->ensure($client->organization, Feature::GridPlanner);
        }

        return DB::transaction(function () use ($client, $user, $data) {
            $plan = GridPlan::create([
                'org_id' => $client->org_id,
                'client_id' => $client->id,
                'social_account_id' => (int) $data['social_account_id'],
                'campaign_id' => $data['campaign_id'] ?? null,
                'name' => trim((string) $data['name']),
                'status' => GridPlanStatus::Draft,
                'review_message' => $data['review_message'] ?? null,
                'pinned_count' => (int) ($data['pinned_count'] ?? 0),
                'include_live_feed' => (bool) ($data['include_live_feed'] ?? true),
                'version' => 1,
                'created_by' => $user->id,
            ]);

            $plan->update(['checklist_snapshot' => ($this->checklist)($plan)]);

            Log::info('Grid plan created', [
                'grid_plan_id' => $plan->id,
                'org_id' => $plan->org_id,
                'client_id' => $client->id,
                'user_id' => $user->id,
            ]);

            return $plan;
        });
    }
}
