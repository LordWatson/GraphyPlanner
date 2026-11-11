<?php

namespace App\Actions\Clients;

use App\Actions\ClientInvitations\CreateClientInvitationAction;
use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — update a client contact's details and notification preferences. Changing the email
 * moves their portal access with them: the old address's pending invite is revoked and the new
 * address is invited.
 */
class UpdateClientContactAction
{
    public function __construct(private CreateClientInvitationAction $invite) {}

    /**
     * @param  array{name?: ?string, email: string, phone?: ?string, role: ClientContactRole, receives_review_emails: bool, receives_invoices: bool, receives_whatsapp?: bool}  $data
     */
    public function __invoke(ClientContact $contact, array $data, User $actingUser): ClientContact
    {
        $previousEmail = $contact->email;

        DB::transaction(function () use ($contact, $data, $actingUser, $previousEmail) {
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
                $contact->setWhatsAppConsent($consented, 'staff', $actingUser->id);
                $contact->save();
            }

            if ($contact->email !== $previousEmail) {
                ClientInvitation::query()
                    ->where('client_id', $contact->client_id)
                    ->where('email', $previousEmail)
                    ->pending()
                    ->update(['revoked_at' => now()]);
            }
        });

        // Outside the transaction: sends mail. An already-accepted login at the old address keeps
        // working — shortcut: renaming the email does not rename the portal user, re-invite only.
        if ($contact->email !== $previousEmail) {
            ($this->invite)($contact->loadMissing('client')->client, $contact->email, $actingUser);
        }

        Log::info('Client contact updated', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'client_contact_id' => $contact->id,
            'email_changed' => $contact->email !== $previousEmail,
        ]);

        return $contact;
    }
}
