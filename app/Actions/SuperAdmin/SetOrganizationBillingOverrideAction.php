<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\PlanAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-2.6 — a super admin overrides the global Stripe switch for one org. `null` clears the
 * override (the org inherits the global switch again); `true`/`false` force it on/off for that org.
 * Audited.
 */
class SetOrganizationBillingOverrideAction
{
    public function __invoke(Organization $organization, ?bool $enabled, User $actor): Organization
    {
        return DB::transaction(function () use ($organization, $enabled, $actor) {
            $organization->billing_enabled = $enabled;
            $organization->save();

            PlanAuditLog::create([
                'org_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => PlanAuditLog::ACTION_BILLING_OVERRIDE,
                'meta' => ['billing_enabled' => $enabled],
            ]);

            Log::info('Super admin set org billing override', [
                'org_id' => $organization->id,
                'user_id' => $actor->id,
                'billing_enabled' => $enabled,
            ]);

            return $organization;
        });
    }
}
