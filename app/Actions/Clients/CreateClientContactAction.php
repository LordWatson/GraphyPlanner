<?php

namespace App\Actions\Clients;

use App\Actions\ClientInvitations\CreateClientInvitationAction;
use App\Enums\ClientContactRole;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — add a named contact to a client. Role-driven defaults for the two email channels are
 * filled only when the caller didn't state a preference.
 *
 * Every contact is also a portal user: adding one sends them the portal signup link, so "who we
 * talk to at this client" and "who can log in" are one list. Which notifications they then get is
 * the contact's own per-channel preferences, not their portal access.
 */
class CreateClientContactAction
{
    public function __construct(private CreateClientInvitationAction $invite) {}

    /**
     * @param  array{name?: ?string, email: string, phone?: ?string, role: ClientContactRole, receives_review_emails?: ?bool, receives_invoices?: ?bool, receives_whatsapp?: bool}  $data
     */
    public function __invoke(Client $client, array $data, User $invitedBy): ClientContact
    {
        $contact = DB::transaction(function () use ($client, $data, $invitedBy) {
            $contact = $client->contacts()->create([
                'org_id' => $client->org_id,
                'name' => $data['name'] ?? null,
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => $data['role'],
                'receives_review_emails' => $data['receives_review_emails'] ?? $data['role']->defaultReceivesReviewEmails(),
                'receives_invoices' => $data['receives_invoices'] ?? $data['role']->defaultReceivesInvoices(),
            ]);

            // Step W-1.2: consent is recorded with its evidence — when, and that a member of staff
            // set it rather than the contact themselves.
            if ($data['receives_whatsapp'] ?? false) {
                $contact->setWhatsAppConsent(true, 'staff', $invitedBy->id);
                $contact->save();
            }

            return $contact;
        });

        // Outside the transaction: the invite sends mail, and a mail failure must not undo the
        // contact (the invite can be re-sent from the contact row).
        ($this->invite)($client, $contact->email, $invitedBy);

        Log::info('Client contact created', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'client_contact_id' => $contact->id,
            'role' => $contact->role->value,
        ]);

        return $contact;
    }
}
