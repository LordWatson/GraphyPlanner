<?php

namespace App\Services\WhatsApp;

use App\Models\Organization;

/**
 * Step W-0.1 — where an organization's WhatsApp credentials come from, in one place.
 *
 * Today that is the platform's own Cloud API number from `config/services.php`: one Meta app, one
 * sending number, every org messaged from it. Step W-4.1 gives an organization its own
 * `whatsapp_phone_number_id` and token (stored like `upload_post_key`), and when it does **only
 * this class changes** — the client and every caller already ask per organization.
 *
 * Nothing here is ever logged; callers resolve a token and use it.
 */
class WhatsAppCredentials
{
    public function accessTokenFor(Organization $organization): string
    {
        return (string) config('services.whatsapp.access_token', '');
    }

    public function phoneNumberIdFor(Organization $organization): string
    {
        return (string) config('services.whatsapp.phone_number_id', '');
    }

    /**
     * Whether this organization can send at all. False keeps every WhatsApp path a silent no-op
     * rather than a failed call.
     */
    public function isConfiguredFor(Organization $organization): bool
    {
        return $this->accessTokenFor($organization) !== '' && $this->phoneNumberIdFor($organization) !== '';
    }
}
