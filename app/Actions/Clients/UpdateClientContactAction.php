<?php

namespace App\Actions\Clients;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — update a client contact's details and email preferences.
 */
class UpdateClientContactAction
{
    /**
     * @param  array{name?: ?string, email: string, phone?: ?string, role: ClientContactRole, receives_review_emails: bool, receives_invoices: bool, receives_whatsapp?: bool}  $data
     */
    public function __invoke(ClientContact $contact, array $data): ClientContact
    {
        $contact->update([
            'name' => $data['name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'receives_review_emails' => $data['receives_review_emails'],
            'receives_invoices' => $data['receives_invoices'],
        ]);

        // Step W-1.2: only ever re-stamped when the answer changes, so an edit to somebody's
        // name does not rewrite the date they consented.
        $consented = $data['receives_whatsapp'] ?? $contact->receives_whatsapp;

        if ($consented !== $contact->receives_whatsapp) {
            $contact->setWhatsAppConsent($consented, 'staff', $this->actingUserId());
            $contact->save();
        }

        Log::info('Client contact updated', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'client_contact_id' => $contact->id,
        ]);

        return $contact;
    }

    /**
     * The member recording the consent, or null when nothing is acting as a user (a job, a
     * command). `auth()->id()` is typed loosely enough to allow a string key; a user's id is an
     * int.
     */
    private function actingUserId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User ? $user->id : null;
    }
}
