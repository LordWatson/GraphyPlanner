<?php

namespace App\Actions\Clients;

use App\Enums\ClientContactRole;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — add a named contact to a client. Role-driven defaults for the two email channels are
 * filled only when the caller didn't state a preference.
 */
class CreateClientContactAction
{
    /**
     * @param  array{name?: ?string, email: string, phone?: ?string, role: ClientContactRole, receives_review_emails?: ?bool, receives_invoices?: ?bool}  $data
     */
    public function __invoke(Client $client, array $data): ClientContact
    {
        $contact = $client->contacts()->create([
            'org_id' => $client->org_id,
            'name' => $data['name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'receives_review_emails' => $data['receives_review_emails'] ?? $data['role']->defaultReceivesReviewEmails(),
            'receives_invoices' => $data['receives_invoices'] ?? $data['role']->defaultReceivesInvoices(),
        ]);

        Log::info('Client contact created', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'client_contact_id' => $contact->id,
            'role' => $contact->role->value,
        ]);

        return $contact;
    }
}
