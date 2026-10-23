<?php

namespace App\Actions\Clients;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — update a client contact's details and email preferences.
 */
class UpdateClientContactAction
{
    /**
     * @param  array{name?: ?string, email: string, phone?: ?string, role: ClientContactRole, receives_review_emails: bool, receives_invoices: bool}  $data
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

        Log::info('Client contact updated', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'client_contact_id' => $contact->id,
        ]);

        return $contact;
    }
}
