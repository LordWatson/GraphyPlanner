<?php

namespace App\Actions\SocialAccounts;

use App\Contracts\PublishAdapter;
use App\Enums\ConnectionStatus;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/**
 * Step 1.6: asks the publish adapter whether a `connected` SocialAccount's vendor token is
 * still valid, flipping `connection_status` to `token_expired` the moment it isn't. This is the
 * only path (besides the OAuth callback) allowed to write `connection_status`, so
 * `ClientHealthService`/Home can trust it as the current truth.
 */
class CheckSocialAccountHealthAction
{
    public function __invoke(PublishAdapter $adapter, SocialAccount $account): bool
    {
        // Step I-3.9 / M-2.7: the stored Meta token is checked (and refreshed) on every sweep,
        // whatever the vendor connection's state.
        app(CheckTokenExpiryAction::class)($account);

        if ($account->connection_status !== ConnectionStatus::Connected) {
            // Nothing to check — `not_connected` has never linked a token, and `token_expired`
            // is only ever cleared by re-running the connect flow (Step 1.3), not by health().
            return false;
        }

        $healthy = $adapter->health($account);

        // Step I-3.9 / M-2.7: the last check and its outcome are kept on the account (shown on
        // the client's Accounts tab).
        $account->update([
            'last_health_check_at' => now(),
            'last_health_error' => $healthy ? null : 'The publishing service no longer accepts this connection. Reconnect the account.',
            ...($healthy ? [] : ['connection_status' => ConnectionStatus::TokenExpired]),
        ]);

        if (! $healthy) {
            Log::warning('SocialAccount health check failed — marking token as expired', [
                'social_account_id' => $account->id,
                'client_id' => $account->client_id,
            ]);
        }

        return $healthy;
    }
}
