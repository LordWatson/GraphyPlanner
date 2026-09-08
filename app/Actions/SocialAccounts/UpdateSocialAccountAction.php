<?php

namespace App\Actions\SocialAccounts;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateSocialAccountAction
{
    /**
     * Update an existing social account.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(SocialAccount $socialAccount, array $data): SocialAccount
    {
        return DB::transaction(function () use ($socialAccount, $data) {
            $socialAccount->update($data);

            Log::info('Social account updated', [
                'client_id' => $socialAccount->client_id,
                'social_account_id' => $socialAccount->id,
            ]);

            return $socialAccount;
        });
    }
}
