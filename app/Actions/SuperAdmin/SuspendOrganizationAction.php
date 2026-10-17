<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step 15.3 — soft, reversible lock-out of every member of an organization. While suspended,
 * `EnsureOrganizationNotSuspended` shows its members a "contact support" page, and scheduled
 * work (publishing, reminders, AI daily briefs) skips the org. Nothing is deleted.
 */
class SuspendOrganizationAction
{
    public function __invoke(Organization $organization, User $actor, ?string $reason = null): Organization
    {
        if ($organization->isPreviewFixture()) {
            throw ValidationException::withMessages([
                'organization' => __('The preview fixture organization can’t be suspended.'),
            ]);
        }

        if ($organization->isSuspended()) {
            return $organization;
        }

        $organization->forceFill([
            'suspended_at' => now(),
            'suspension_reason' => filled($reason) ? trim((string) $reason) : null,
            'suspended_by' => $actor->id,
        ])->save();

        Log::warning('Organization suspended', [
            'org_id' => $organization->id,
            'user_id' => $actor->id,
        ]);

        return $organization;
    }
}
