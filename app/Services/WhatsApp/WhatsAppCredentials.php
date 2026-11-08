<?php

namespace App\Services\WhatsApp;

use App\Contracts\Entitlements;
use App\Enums\Feature;
use App\Models\Organization;

/**
 * Step W-0.1 / W-4.1 — where an organization's Twilio credentials come from, in one place.
 *
 * An organization's own credentials win (stored on `organizations`, the auth token encrypted at
 * rest exactly like `upload_post_key`); the platform's values in `config/services.php` are the
 * fallback, which is what makes a shared sandbox number work in development and a single-tenant
 * deployment work without per-org setup.
 *
 * An organization with `whatsapp_enabled` false is treated as unconfigured however complete its
 * credentials are — that checkbox is the off switch an Owner gets (W-4.1).
 *
 * Nothing here is ever logged, and none of it is ever sent to the frontend.
 */
class WhatsAppCredentials
{
    public function accountSidFor(Organization $organization): string
    {
        return $this->resolve($organization, 'whatsapp_account_sid', 'account_sid');
    }

    public function authTokenFor(Organization $organization): string
    {
        return $this->resolve($organization, 'whatsapp_auth_token', 'auth_token');
    }

    /**
     * The WhatsApp-enabled sender number, in E.164 without the `whatsapp:` prefix.
     */
    public function fromNumberFor(Organization $organization): string
    {
        return $this->resolve($organization, 'whatsapp_from', 'from');
    }

    public function messagingServiceSidFor(Organization $organization): string
    {
        return $this->resolve($organization, 'whatsapp_messaging_service_sid', 'messaging_service_sid');
    }

    /**
     * Whether this organization can send at all. False keeps every WhatsApp path a silent no-op
     * rather than a failed call.
     */
    public function isConfiguredFor(Organization $organization): bool
    {
        if ($organization->whatsapp_enabled === false) {
            return false;
        }

        // Step W-4.3 — the plan gate, checked in the one place every send already passes through
        // rather than scattered through the notifications. A downgrade switches WhatsApp off
        // without throwing the credentials away.
        if (! app(Entitlements::class)->allows($organization, Feature::WhatsApp)) {
            return false;
        }

        return $this->accountSidFor($organization) !== ''
            && $this->authTokenFor($organization) !== ''
            && ($this->fromNumberFor($organization) !== '' || $this->messagingServiceSidFor($organization) !== '');
    }

    /**
     * The organization's own value, else the platform's.
     */
    private function resolve(Organization $organization, string $column, string $configKey): string
    {
        $own = $organization->getAttribute($column);

        if (is_string($own) && $own !== '') {
            return $own;
        }

        return (string) config('services.whatsapp.'.$configKey, '');
    }
}
