<?php

namespace App\Services\Plans;

use App\Models\Organization;
use App\Services\Platform\PlatformSettings;
use Illuminate\Support\Facades\Log;

/**
 * Phase CG-2 (Step CG-2.4 / CG-2.6) — the single answer to "may this org see Stripe checkout and
 * billing?". A global super-admin switch ({@see PlatformSettings::billingEnabled()}) is the master;
 * a per-org override (`organizations.billing_enabled`) wins when set either way, so a super admin can
 * let one org through while the platform is still dark (or hold one back while everyone else is live).
 * Both answers are overruled by {@see configured()}: no Stripe secret, no billing.
 */
class BillingGate
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function globallyEnabled(): bool
    {
        return $this->settings->billingEnabled();
    }

    /**
     * Whether Stripe credentials exist at all. Without a secret key every Cashier call throws
     * `api_key cannot be the empty string` from inside the SDK — a 500 on a button press — so this
     * is a hard precondition that even a per-org override can't talk its way past.
     */
    public function configured(): bool
    {
        return is_string(config('cashier.secret')) && config('cashier.secret') !== '';
    }

    public function enabledForOrg(?Organization $organization): bool
    {
        if ($organization === null) {
            return false;
        }

        $enabled = $organization->billing_enabled ?? $this->globallyEnabled();

        if ($enabled && ! $this->configured()) {
            // Only reached when someone switched billing on without a key — a deployment mistake,
            // not a state anyone chose, so say so rather than fail quietly.
            Log::warning('Billing is switched on but no Stripe secret is configured; treating billing as unavailable', [
                'org_id' => $organization->id,
            ]);

            return false;
        }

        return $enabled;
    }
}
