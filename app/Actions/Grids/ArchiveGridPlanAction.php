<?php

namespace App\Actions\Grids;

use App\Enums\GridPlanStatus;
use App\Models\GridPlan;
use App\Models\User;

class ArchiveGridPlanAction
{
    public function __construct(private readonly TransitionGridPlanStatusAction $transition = new TransitionGridPlanStatusAction) {}

    /**
     * Step 18.4 — put a draft or approved grid away (`archived` through the normal transition, so
     * the move is logged and checked against the map). Its posts are untouched. Restoring it is
     * the `archived → draft` move.
     */
    public function __invoke(GridPlan $plan, User $user, ?string $note = null): GridPlan
    {
        return ($this->transition)($plan, GridPlanStatus::Archived, $user, $note);
    }
}
