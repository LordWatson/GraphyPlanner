<?php

namespace App\Services\Publishing;

use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Meta (Facebook Login for Business) long-lived user tokens — the Instagram music-search
 * connection (Step 1.9). Exchanging a token for a long-lived one (`fb_exchange_token`) works for
 * a still-valid long-lived token too, which is how Step I-3.9 / M-2.7 refreshes it before it
 * expires. Tokens are never logged.
 */
class MetaTokenService
{
    /**
     * @return array{0: string, 1: CarbonInterface|null} the new token and when it expires
     *
     * @throws RuntimeException
     */
    public function exchange(string $token): array
    {
        $response = Http::timeout(20)->get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $token,
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException((string) ($response->json('error.message') ?? 'Unable to obtain a long-lived Facebook access token.'));
        }

        $expiresIn = $response->json('expires_in');

        return [(string) $response->json('access_token'), $expiresIn ? now()->addSeconds((int) $expiresIn) : null];
    }

    /**
     * Swap the account's token for a fresh long-lived one. Returns false (and leaves the account
     * as it was) when Meta won't refresh it — e.g. it already expired or access was revoked.
     */
    public function refresh(SocialAccount $account): bool
    {
        if (blank($account->meta_access_token) || blank(config('services.facebook.client_id'))) {
            return false;
        }

        try {
            [$token, $expiresAt] = $this->exchange((string) $account->meta_access_token);
        } catch (Throwable $e) {
            Log::warning('Meta token refresh failed', ['social_account_id' => $account->id, 'error' => $e->getMessage()]);

            return false;
        }

        $account->update([
            'meta_access_token' => $token,
            'meta_access_token_expires_at' => $expiresAt,
            'token_expiry_warning' => null,
        ]);

        Log::info('Meta token refreshed', ['social_account_id' => $account->id, 'expires_at' => $expiresAt?->toIso8601String()]);

        return true;
    }

    /**
     * Same base and version as the connect flow (`services.facebook.graph_base_url` /
     * `graph_version`), and only ever a facebook.com host.
     */
    private function graphUrl(string $path): string
    {
        $base = rtrim((string) config('services.facebook.graph_base_url', 'https://graph.facebook.com'), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);

        if (! str_ends_with($host, 'facebook.com')) {
            throw new RuntimeException('services.facebook.graph_base_url must point to facebook.com.');
        }

        return $base.'/'.config('services.facebook.graph_version', 'v20.0').$path;
    }
}
