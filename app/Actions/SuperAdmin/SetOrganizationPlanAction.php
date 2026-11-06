<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\PlanAuditLog;
use App\Models\User;
use App\Support\Plans\PlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Step CG-2.6 / B-9.4 — a super admin overrides an org's plan. `$planKey = null` resets it to the
 * default for its workspace type. Every change is audited.
 */
class SetOrganizationPlanAction
{
    public function __construct(private readonly PlanCatalog $catalog) {}

    public function __invoke(Organization $organization, ?string $planKey, User $actor, ?string $note = null): Organization
    {
        if ($planKey !== null && $this->catalog->find($planKey) === null) {
            throw new InvalidArgumentException("Unknown plan [{$planKey}].");
        }

        return DB::transaction(function () use ($organization, $planKey, $actor, $note) {
            $from = $organization->plan;

            $organization->plan = $planKey;
            $organization->save();

            PlanAuditLog::create([
                'org_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => PlanAuditLog::ACTION_PLAN_CHANGED,
                'from_plan' => $from,
                'to_plan' => $planKey,
                'note' => $note,
            ]);

            Log::info('Super admin changed organization plan', [
                'org_id' => $organization->id,
                'user_id' => $actor->id,
                'from' => $from,
                'to' => $planKey,
            ]);

            return $organization;
        });
    }
}
