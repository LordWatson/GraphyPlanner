<?php

namespace App\Actions\Portal;

use App\Models\PortalLoginToken;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step M-6.2 — mint a single-use, expiring magic-link token for a client reviewer, optionally
 * landing on a specific post. Hashed at rest; the plain token is only ever the return value.
 */
class CreatePortalLoginTokenAction
{
    /**
     * @return array{token: string, model: PortalLoginToken}
     */
    public function __invoke(User $user, ?Post $post = null, ?CarbonInterface $expiresAt = null): array
    {
        $plainToken = Str::random(64);

        $token = PortalLoginToken::create([
            'user_id' => $user->id,
            'token_hash' => PortalLoginToken::hashToken($plainToken),
            'post_id' => $post?->id,
            'expires_at' => $expiresAt ?? now()->addDays(PortalLoginToken::DEFAULT_TTL_DAYS),
        ]);

        Log::info('Portal login token created', [
            'user_id' => $user->id,
            'post_id' => $post?->id,
            'portal_login_token_id' => $token->id,
        ]);

        return ['token' => $plainToken, 'model' => $token];
    }
}
