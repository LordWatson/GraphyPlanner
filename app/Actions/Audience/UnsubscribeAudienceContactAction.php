<?php

namespace App\Actions\Audience;

use App\Models\AudienceContact;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-2.1 — one click, no questions, no confirmation step. The row is kept rather than
 * deleted: a suppression that can be wiped by the next import isn't a suppression.
 */
class UnsubscribeAudienceContactAction
{
    public function __invoke(AudienceContact $contact): bool
    {
        if ($contact->unsubscribed_at !== null) {
            return false;
        }

        $contact->update(['unsubscribed_at' => now()]);

        Log::info('Audience contact unsubscribed', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'contact_id' => $contact->id,
        ]);

        return true;
    }
}
