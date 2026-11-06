<?php

namespace App\Services\Plans;

use App\Models\Organization;
use App\Services\Platform\PlatformSettings;

/**
 * Phase CG-2 (Step CG-2.4 / CG-2.6) — the single answer to "may this org see Stripe checkout and
 * billing?". A global super-admin switch ({@see PlatformSettings::billingEnabled()}) is the master;
 * a per-org override (`organizations.billing_enabled`) wins when set either way, so a super admin can
 * let one org through while the platform is still dark (or hold one back while everyone else is live).
 */
class BillingGate
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function globallyEnabled(): bool
    {
        return $this->settings->billingEnabled();
    }

    public function enabledForOrg(?Organization $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        if ($organization->billing_enabled !== null) {
            return $organization->billing_enabled;
        }

        return $this->globallyEnabled();
    }
}
