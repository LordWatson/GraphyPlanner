<?php

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Step I-1.6 — signed, single-use `state` values for the account-connect callbacks (Upload-Post's
 * hosted linking page, and Facebook Login for Instagram audio). Each state is random, cached
 * against the account (and the user who started the flow) for 10 minutes under a hash of the
 * value, and pulled on first use — so a callback with a missing, forged, expired or replayed
 * state is rejected instead of trusting whatever query string the browser arrives with.
 */
class ConnectStateStore
{
    public const TTL_MINUTES = 10;

    public function issue(SocialAccount $account, ?User $user, string $flow): string
    {
        $state = Str::random(48);

        Cache::put($this->key($flow, $state), [
            'social_account_id' => $account->id,
            'user_id' => $user?->id,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $state;
    }

    /**
     * Pull (single use) the payload for a state, or null when it's missing/unknown/expired/used.
     *
     * @return array{social_account_id: int, user_id: int|null}|null
     */
    public function consume(mixed $state, string $flow): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        /** @var array{social_account_id: int, user_id: int|null}|null $payload */
        $payload = Cache::pull($this->key($flow, $state));

        return is_array($payload) ? $payload : null;
    }

    private function key(string $flow, string $state): string
    {
        return "connect-state:{$flow}:".hash('sha256', $state);
    }
}
