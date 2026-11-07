<?php

namespace App\Actions\Audience;

use App\Enums\AudienceTrigger;
use App\Models\AudienceContact;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-2.1 — the double opt-in. Clicking the emailed link is the single moment a sign-up
 * becomes consent, and the welcome email is sent on that transition only, so following the link
 * twice doesn't send it twice.
 *
 * An unsubscribed contact is **not** re-consented by following an old confirmation link.
 */
class ConfirmAudienceContactAction
{
    public function __construct(private readonly SendAudienceTriggerAction $send) {}

    /**
     * @return bool Whether this call is what confirmed them (false = already confirmed, or
     *              unsubscribed and left alone).
     */
    public function __invoke(AudienceContact $contact): bool
    {
        if ($contact->unsubscribed_at !== null || $contact->consent_at !== null) {
            return false;
        }

        $contact->update(['consent_at' => now()]);

        Log::info('Audience contact confirmed', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'contact_id' => $contact->id,
        ]);

        ($this->send)($contact->refresh(), AudienceTrigger::Welcome);

        return true;
    }
}
