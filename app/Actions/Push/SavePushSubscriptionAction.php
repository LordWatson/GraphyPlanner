<?php

namespace App\Actions\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step B-7.4 — store (or refresh) a browser's push subscription for a user. Keyed on the endpoint
 * hash so re-subscribing the same browser updates its keys in place rather than piling up rows;
 * ownership is re-asserted on every save so a recycled endpoint can't stay attached to another user.
 */
class SavePushSubscriptionAction
{
    public function __invoke(
        User $user,
        string $endpoint,
        string $publicKey,
        string $authToken,
        ?string $contentEncoding = null,
    ): PushSubscription {
        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($endpoint)],
            [
                'user_id' => $user->id,
                'endpoint' => $endpoint,
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => $contentEncoding,
            ],
        );

        Log::info('Push subscription saved', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'new' => $subscription->wasRecentlyCreated,
        ]);

        return $subscription;
    }
}
