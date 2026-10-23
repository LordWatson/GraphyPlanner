<?php

namespace App\Actions\Clients;

use App\Models\ClientContact;
use Illuminate\Support\Facades\Log;

/**
 * Step M-6.1 — remove a client contact (soft delete, so the row's history survives).
 */
class DeleteClientContactAction
{
    public function __invoke(ClientContact $contact): void
    {
        $contact->delete();

        Log::info('Client contact removed', [
            'org_id' => $contact->org_id,
            'client_id' => $contact->client_id,
            'client_contact_id' => $contact->id,
        ]);
    }
}
