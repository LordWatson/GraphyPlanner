<?php

namespace App\Policies;

use App\Models\TaxRate;
use App\Models\User;

/**
 * Step M-4.1 — tax rates are part of an organization's settings, so managing them follows the same
 * Owner-only rule as the rest of Settings (mirrors `OrganizationPolicy::update`). They're read by
 * every billing user when picking a rate on an invoice, but only an Owner adds, edits or archives
 * them.
 */
class TaxRatePolicy
{
    /**
     * Manage the organization's tax-rate list (settings-level, Owner-only).
     */
    public function manage(User $user): bool
    {
        return $user->org_id !== null && $user->isOwner();
    }

    /**
     * Act on a specific tax rate — same-org Owner.
     */
    public function update(User $user, TaxRate $taxRate): bool
    {
        return $user->org_id === $taxRate->org_id && $user->isOwner();
    }
}
