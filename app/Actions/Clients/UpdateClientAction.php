<?php

namespace App\Actions\Clients;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateClientAction
{
    /**
     * Update an existing client.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, array $data): Client
    {
        return DB::transaction(function () use ($client, $data) {
            $client->update($data);

            Log::info('Client updated', [
                'client_id' => $client->id,
                'org_id' => $client->org_id,
            ]);

            return $client;
        });
    }
}
