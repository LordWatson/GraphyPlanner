<?php

namespace App\Actions\SuperAdmin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step 15.3 — a super admin edits any organization's core details. API keys and the invoicing
 * profile stay owner-managed (organization settings); this only covers what support needs.
 */
class UpdateOrganizationAction
{
    /**
     * @param  array{name: string, slug: string, default_timezone: string, default_currency: string, ai_monthly_image_quota: int|null, is_design_partner: bool}  $data
     */
    public function __invoke(Organization $organization, array $data, User $actor): Organization
    {
        $organization->fill([
            'name' => trim($data['name']),
            'slug' => $data['slug'],
            'default_timezone' => $data['default_timezone'],
            'default_currency' => strtoupper($data['default_currency']),
            // null = fall back to the platform default quota.
            'ai_monthly_image_quota' => $data['ai_monthly_image_quota'],
            // Step M-8.1 — the design-partner offer flag (drives the partner view and the M-10 coupon).
            'is_design_partner' => $data['is_design_partner'],
        ]);

        $changed = array_keys($organization->getDirty());

        $organization->save();

        Log::info('Super admin updated organization', [
            'org_id' => $organization->id,
            'user_id' => $actor->id,
            'changed' => $changed,
        ]);

        return $organization;
    }
}
