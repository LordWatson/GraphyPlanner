<?php

namespace App\Actions\Clients;

use App\Enums\ClientStatus;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step I-1.5 — bring an archived client back exactly as it was (posts, invoices, accounts), as
 * `active`. Scheduled posts whose time passed while archived publish on their next job run.
 */
class RestoreClientAction
{
    public function __invoke(Client $client, User $actor): Client
    {
        DB::transaction(function () use ($client) {
            $client->restore();
            $client->update(['status' => ClientStatus::Active]);
        });

        Log::info('Client restored', [
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'user_id' => $actor->id,
        ]);

        return $client;
    }
}
