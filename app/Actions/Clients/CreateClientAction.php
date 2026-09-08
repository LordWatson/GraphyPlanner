<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateClientAction
{
    /**
     * Create a new client for the given user's organization.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, array $data): Client
    {
        return DB::transaction(function () use ($user, $data) {
            $client = Client::create([
                ...$data,
                'org_id' => $user->org_id,
            ]);

            Log::info('Client created', [
                'client_id' => $client->id,
                'org_id' => $user->org_id,
                'created_by' => $user->id,
            ]);

            return $client;
        });
    }
}
