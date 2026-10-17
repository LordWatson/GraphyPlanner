<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.3 — lifts a suspension: members regain access on their next request.
 */
class ReinstateOrganizationAction
{
    public function __invoke(Organization $organization, User $actor): Organization
    {
        if (! $organization->isSuspended()) {
            return $organization;
        }

        $organization->forceFill([
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_by' => null,
        ])->save();

        Log::info('Organization reinstated', [
            'org_id' => $organization->id,
            'user_id' => $actor->id,
        ]);

        return $organization;
    }
}
