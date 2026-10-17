<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step 16.11: set (or clear, null = unlimited) the org's monthly AI image quota.
 */
class UpdateAiImageQuotaAction
{
    public function __invoke(Organization $organization, User $user, ?int $quota): Organization
    {
        $previous = $organization->ai_monthly_image_quota;

        $organization->update(['ai_monthly_image_quota' => $quota]);

        Log::info('AI image quota updated', [
            'org_id' => $organization->id,
            'user_id' => $user->id,
            'from' => $previous,
            'to' => $quota,
        ]);

        return $organization;
    }
}
