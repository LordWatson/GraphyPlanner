<?php

namespace App\Actions\Clients;

use App\Enums\Role;
use App\Models\ClientContact;
use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — remove a client contact (soft delete, so the row's history survives). Their portal
 * access goes with it, because contacts and portal logins are one list: a pending invite is
 * revoked, and an accepted login is detached from the client so `/portal/*` turns them away. The
 * user row itself stays, so whatever they approved or commented on keeps its author.
 */
class DeleteClientContactAction
{
    public function __invoke(ClientContact $contact): void
    {
        DB::transaction(function () use ($contact) {
            ClientInvitation::query()
                ->where('client_id', $contact->client_id)
                ->where('email', $contact->email)
                ->pending()
                ->update(['revoked_at' => now()]);

            User::query()
                ->where('role', Role::ClientReviewer)
                ->where('client_id', $contact->client_id)
                ->where('email', $contact->email)
                ->update(['client_id' => null]);

            $contact->delete();
        });

        Log::info('Client contact removed', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'client_contact_id' => $contact->id,
        ]);
    }
}
