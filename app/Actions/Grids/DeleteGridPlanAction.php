<?php

namespace App\Actions\Grids;

use App\Models\GridPlan;
use App\Models\ReviewToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteGridPlanAction
{
    /**
     * Step 18.4 — soft-delete a grid plan and revoke its live review links. Posts made from its
     * tiles are real posts now and stay exactly as they are.
     */
    public function __invoke(GridPlan $plan, User $user): void
    {
        DB::transaction(function () use ($plan) {
            ReviewToken::query()->where('grid_plan_id', $plan->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $plan->delete();
        });

        Log::info('Grid plan deleted', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
        ]);
    }
}
