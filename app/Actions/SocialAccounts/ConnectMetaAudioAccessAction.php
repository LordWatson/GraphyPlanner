<?php

namespace App\Actions\SocialAccounts;

use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists the Meta user access token/Instagram Business user id obtained via the additive
 * Facebook Login connect flow (Step 1.9.3) onto a `SocialAccount`. This is purely additive — it
 * only grants the extra Graph API scope needed for Instagram audio search (Step 1.9.4) and never
 * touches the account's existing Upload-Post connection state (`connection_status`,
 * `external_profile_id`, etc. from Step 1.3).
 */
class ConnectMetaAudioAccessAction
{
    public function __invoke(
        SocialAccount $socialAccount,
        string $accessToken,
        ?CarbonInterface $expiresAt,
        string $instagramUserId,
    ): SocialAccount {
        DB::transaction(function () use ($socialAccount, $accessToken, $expiresAt, $instagramUserId) {
            $socialAccount->update([
                'meta_access_token' => $accessToken,
                'meta_access_token_expires_at' => $expiresAt,
                'meta_instagram_user_id' => $instagramUserId,
            ]);
        });

        // Never log the token itself, only the account it was granted for.
        Log::info('Meta audio-search access connected for social account', [
            'social_account_id' => $socialAccount->id,
        ]);

        return $socialAccount->refresh();
    }
}
