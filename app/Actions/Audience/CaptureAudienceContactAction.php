<?php

namespace App\Actions\Audience;

use App\Enums\AudienceSource;
use App\Models\AudienceContact;
use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step GTM-2.1 — the **only** way a contact is created. Every capture path (the bio page's sign-up
 * block, a checkout, a CSV import) comes through here, so the consent rules live in one place:
 *
 *  - **Never a duplicate.** `(client_id, email)` is unique; a second capture finds the row.
 *  - **Never a downgrade.** An existing contact keeps its consent and its original source; the
 *    name is only filled in if we didn't have one.
 *  - **Never a resurrection.** An unsubscribed contact stays unsubscribed, whatever the new
 *    capture claims. An import or a fresh purchase must not undo an unsubscribe.
 *  - **Consent is only ever the person's.** `$consented` is true only where the person themselves
 *    said so (the marketing box at checkout); a purchase, an import or a sign-up never sets it —
 *    a sign-up is confirmed by {@see ConfirmAudienceContactAction} instead.
 *
 * Not plan-gated: *sending* is what `Feature::EmailAutomation` gates. Refusing to record a buyer's
 * address would lose the creator real data over a plan they can still upgrade to.
 */
class CaptureAudienceContactAction
{
    public function __invoke(
        Client $brand,
        string $email,
        ?string $name = null,
        AudienceSource $source = AudienceSource::BioCapture,
        bool $consented = false,
    ): AudienceContact {
        $email = strtolower(trim($email));
        $name = trim((string) $name) === '' ? null : trim((string) $name);

        $contact = DB::transaction(function () use ($brand, $email, $name, $source, $consented): AudienceContact {
            $contact = AudienceContact::query()
                ->withoutOrgScope()
                ->where('client_id', $brand->id)
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if ($contact === null) {
                return AudienceContact::create([
                    'org_id' => $brand->org_id,
                    'client_id' => $brand->id,
                    'email' => $email,
                    'name' => $name,
                    'source' => $source,
                    'consent_at' => $consented ? now() : null,
                ]);
            }

            $contact->update([
                'name' => $contact->name ?? $name,
                // Only ever upwards, and never against an unsubscribe.
                'consent_at' => $contact->consent_at ?? (
                    $consented && $contact->unsubscribed_at === null ? now() : null
                ),
            ]);

            return $contact;
        });

        Log::info('Audience contact captured', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'contact_id' => $contact->id,
            'source' => $source->value,
            'consented' => $contact->consent_at !== null,
        ]);

        return $contact;
    }
}
