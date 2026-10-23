<?php

namespace App\Services\DesignImports;

use App\Contracts\DesignImportSource;
use App\Exceptions\DesignImportReconnectRequired;
use App\Models\DesignImportConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Step M-5.9 — shared OAuth2 authorization-code plumbing for the design/cloud sources: building the
 * authorize URL, exchanging the callback code, and making authorized requests that refresh an
 * expired token once and otherwise raise `DesignImportReconnectRequired`. Tokens are only ever held
 * on the (encrypted) connection — never logged. Concrete sources add the provider endpoints and the
 * `listFiles`/`download` calls.
 */
abstract class OAuth2ImportSource implements DesignImportSource
{
    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /**
     * @return list<string>
     */
    abstract protected function scopes(): array;

    /**
     * Extra query params on the authorize URL (e.g. Google's `access_type=offline`).
     *
     * @return array<string, string>
     */
    protected function authorizeParams(): array
    {
        return [];
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("design-imports.{$this->provider()->value}.{$key}", $default);
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return $this->authorizeEndpoint().'?'.http_build_query([
            'client_id' => $this->config('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            ...$this->authorizeParams(),
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post($this->tokenEndpoint(), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('OAuth token exchange failed.');
        }

        return $this->tokensFromResponse((array) $response->json());
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{access_token: string, refresh_token?: string|null, expires_at?: CarbonInterface|null}
     */
    protected function tokensFromResponse(array $json): array
    {
        $tokens = ['access_token' => (string) ($json['access_token'] ?? '')];

        if (isset($json['refresh_token'])) {
            $tokens['refresh_token'] = (string) $json['refresh_token'];
        }
        if (isset($json['expires_in'])) {
            $tokens['expires_at'] = now()->addSeconds((int) $json['expires_in']);
        }

        return $tokens;
    }

    /**
     * An authorized HTTP client for the connection, refreshing the access token first if it's
     * expired. Throws `DesignImportReconnectRequired` for a revoked/expired-unrefreshable
     * connection.
     */
    protected function request(DesignImportConnection $connection): PendingRequest
    {
        return Http::withToken($this->freshAccessToken($connection));
    }

    protected function freshAccessToken(DesignImportConnection $connection): string
    {
        if ($connection->isRevoked()) {
            throw new DesignImportReconnectRequired($connection);
        }

        if (! $connection->isExpired() && filled($connection->access_token)) {
            return (string) $connection->access_token;
        }

        if (blank($connection->refresh_token)) {
            throw new DesignImportReconnectRequired($connection);
        }

        $response = Http::asForm()->post($this->tokenEndpoint(), [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
        ]);

        if ($response->failed()) {
            throw new DesignImportReconnectRequired($connection);
        }

        $tokens = $this->tokensFromResponse((array) $response->json());

        $connection->update([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => $tokens['expires_at'] ?? null,
        ]);

        return $tokens['access_token'];
    }

    /**
     * Treat a 401/403 from a provider call as "the connection died" so the UI prompts a reconnect.
     */
    protected function reconnectOnAuthFailure(DesignImportConnection $connection, int $status): void
    {
        if (in_array($status, [401, 403], true)) {
            throw new DesignImportReconnectRequired($connection);
        }
    }
}
