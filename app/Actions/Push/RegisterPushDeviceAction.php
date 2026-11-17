<?php

namespace App\Actions\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Native shell — store (or refresh) a phone's FCM device token for a user. Keyed on the token's
 * hash like a browser endpoint, so a re-registration on app start updates the row in place and
 * ownership is re-asserted (a phone handed to a colleague stops buzzing for the last person).
 * `updated_at` is touched on every call: it is the "last seen" stamp. The token is never logged.
 */
class RegisterPushDeviceAction
{
    public function __invoke(User $user, string $token, string $platform): PushSubscription
    {
        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($token)],
            ['user_id' => $user->id, 'platform' => $platform, 'endpoint' => $token],
        );
        $subscription->touch();

        Log::info('Push device registered', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'platform' => $platform,
            'new' => $subscription->wasRecentlyCreated,
        ]);

        return $subscription;
    }
}
