<?php

namespace App\Actions\SocialAccounts;

use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateSocialAccountAction
{
    /**
     * Create a new social account for the given client.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Client $client, array $data): SocialAccount
    {
        return DB::transaction(function () use ($client, $data) {
            $socialAccount = SocialAccount::create([
                ...$data,
                'org_id' => $client->org_id,
                'client_id' => $client->id,
            ]);

            Log::info('Social account created', [
                'client_id' => $client->id,
                'social_account_id' => $socialAccount->id,
                'platform' => $socialAccount->platform->value,
            ]);

            return $socialAccount;
        });
    }
}
