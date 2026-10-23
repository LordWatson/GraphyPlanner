<?php

namespace App\Actions\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step B-7.4 — opt a browser out of push. Deletes by endpoint, scoped to the user's own rows (a
 * user can only ever drop their own subscription). Returns the number removed so the caller can
 * report whether anything was actually there.
 */
class DeletePushSubscriptionAction
{
    public function __invoke(User $user, string $endpoint): int
    {
        $deleted = PushSubscription::query()
            ->where('user_id', $user->id)
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();

        if ($deleted > 0) {
            Log::info('Push subscription removed', [
                'user_id' => $user->id,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }
}
