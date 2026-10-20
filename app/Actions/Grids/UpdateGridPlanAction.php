<?php

namespace App\Actions\Grids;

use App\Models\GridPlan;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use Illuminate\Support\Facades\DB;

class UpdateGridPlanAction
{
    private const FIELDS = ['name', 'campaign_id', 'pinned_count', 'review_message', 'include_live_feed'];

    public function __construct(private readonly GridPlanChanges $changes = new GridPlanChanges) {}

    /**
     * Step 18.4 — the plan's settings: name, campaign, pinned post count (which shifts the whole
     * grid), the note for the client and whether the preview shows the live feed. Only the fields
     * sent change. Changing the pinned count changes the look, so it reopens an approved plan to
     * `draft`; the other settings leave the approval alone.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(GridPlan $plan, User $user, array $data): GridPlan
    {
        $updated = DB::transaction(function () use ($plan, $user, $data) {
            $locked = $this->changes->lock($plan);
            $changes = array_intersect_key($data, array_flip(self::FIELDS));

            if (isset($changes['name'])) {
                $changes['name'] = trim((string) $changes['name']);
            }

            $locked->fill($changes);
            $affectsLook = $locked->isDirty('pinned_count');
            $locked->save();

            return $this->changes->record($locked, $user, 'settings', $affectsLook);
        });

        $plan->setRawAttributes($updated->getAttributes(), true);

        return $plan;
    }
}
