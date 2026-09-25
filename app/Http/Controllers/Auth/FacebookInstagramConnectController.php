<?php

namespace App\Http\Controllers\Auth;

use App\Actions\SocialAccounts\ConnectMetaAudioAccessAction;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Step 1.9.3 — the additive Facebook Login (for Business) connect flow that grants the Meta
 * Graph API access needed for Instagram audio search (Step 1.9.4). This is a thin, direct
 * `Http`-based OAuth exchange rather than Laravel Socialite (not installed in this app) —
 * Facebook's OAuth dialog/token-exchange endpoints are simple enough not to need the package.
 *
 * This does **not** replace or change how Instagram accounts are connected for publishing via
 * Upload-Post (Step 1.3) — it only adds the extra grant these three new `SocialAccount` columns
 * (Step 1.9.2) carry.
 */
class FacebookInstagramConnectController extends Controller
{
    /**
     * Redirect the browser to Facebook's OAuth dialog for the given social account.
     */
    public function redirect(Client $client, SocialAccount $socialAccount): RedirectResponse
    {
        $this->authorize('update', $socialAccount);

        abort_unless($socialAccount->client_id === $client->id, 404);

        $params = [
            'client_id' => config('services.facebook.client_id'),
            'redirect_uri' => $this->redirectUri($client, $socialAccount),
            'scope' => 'instagram_basic,instagram_content_publish',
            'state' => $socialAccount->id,
            'response_type' => 'code',
        ];

        return redirect()->away('https://www.facebook.com/'.$this->graphVersion().'/dialog/oauth?'.http_build_query($params));
    }

    /**
     * Handle Facebook's OAuth redirect back: exchange the authorization code for a long-lived
     * user access token, resolve the connected Instagram Business user id, then persist both via
     * ConnectMetaAudioAccessAction.
     */
    public function callback(
        Request $request,
        Client $client,
        SocialAccount $socialAccount,
        ConnectMetaAudioAccessAction $action,
    ): RedirectResponse {
        $this->authorize('update', $socialAccount);

        abort_unless($socialAccount->client_id === $client->id, 404);

        $code = $request->query('code');

        if (! $code) {
            Log::warning('Facebook Instagram connect callback missing authorization code', [
                'social_account_id' => $socialAccount->id,
            ]);

            return to_route('clients.show', $client)->with('error', 'Instagram connection via Facebook failed.');
        }

        try {
            $shortLivedToken = $this->exchangeCodeForToken($code, $this->redirectUri($client, $socialAccount));
            [$accessToken, $expiresAt] = $this->exchangeForLongLivedToken($shortLivedToken);
            $instagramUserId = $this->resolveInstagramBusinessUserId($accessToken);
        } catch (RuntimeException $e) {
            Log::error('Facebook Instagram connect callback failed', [
                'social_account_id' => $socialAccount->id,
                'error' => $e->getMessage(),
            ]);

            return to_route('clients.show', $client)->with('error', 'Instagram connection via Facebook failed: '.$e->getMessage());
        }

        $action($socialAccount, $accessToken, $expiresAt, $instagramUserId);

        return to_route('clients.show', $client)->with('success', 'Instagram connected for music search.');
    }

    private function exchangeCodeForToken(string $code, string $redirectUri): string
    {
        $response = Http::get($this->graphUrl('/oauth/access_token'), [
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException($response->json('error.message') ?? 'Facebook rejected the authorization code.');
        }

        return $response->json('access_token');
    }

    /**
     * @return array{0: string, 1: ?CarbonInterface}
     */
    private function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.facebook.client_id'),
            'client_secret' => config('services.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new RuntimeException($response->json('error.message') ?? 'Unable to obtain a long-lived Facebook access token.');
        }

        $expiresIn = $response->json('expires_in');

        return [
            $response->json('access_token'),
            $expiresIn ? now()->addSeconds((int) $expiresIn) : null,
        ];
    }

    private function resolveInstagramBusinessUserId(string $accessToken): string
    {
        $pages = Http::get($this->graphUrl('/me/accounts'), [
            'access_token' => $accessToken,
        ]);

        if (! $pages->successful()) {
            throw new RuntimeException($pages->json('error.message') ?? 'Unable to list connected Facebook Pages.');
        }

        foreach ($pages->json('data') ?? [] as $page) {
            $pageDetails = Http::get($this->graphUrl('/'.$page['id']), [
                'fields' => 'instagram_business_account',
                'access_token' => $accessToken,
            ]);

            $igUserId = $pageDetails->json('instagram_business_account.id');

            if ($igUserId) {
                return (string) $igUserId;
            }
        }

        throw new RuntimeException('No Instagram Business account is connected to any of this Facebook Page(s).');
    }

    private function redirectUri(Client $client, SocialAccount $socialAccount): string
    {
        return config('services.facebook.redirect_uri')
            ?? route('social-accounts.facebook.callback', [$client, $socialAccount]);
    }

    private function graphUrl(string $path): string
    {
        return rtrim((string) config('services.facebook.graph_base_url', 'https://graph.facebook.com'), '/')
            .'/'.$this->graphVersion().$path;
    }

    private function graphVersion(): string
    {
        return config('services.facebook.graph_version', 'v20.0');
    }
}
