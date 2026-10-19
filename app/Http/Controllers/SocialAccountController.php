<?php

namespace App\Http\Controllers;

use App\Actions\SocialAccounts\ConnectSocialAccountAction;
use App\Actions\SocialAccounts\CreateSocialAccountAction;
use App\Actions\SocialAccounts\RecordClientConnectionAction;
use App\Actions\SocialAccounts\UpdateSocialAccountAction;
use App\Contracts\PublishAdapter;
use App\Http\Requests\StoreSocialAccountRequest;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Models\Client;
use App\Models\ConnectInvite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ConnectStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class SocialAccountController extends Controller
{
    /**
     * Create a new social account for the given client.
     */
    public function store(StoreSocialAccountRequest $request, Client $client, CreateSocialAccountAction $action): RedirectResponse
    {
        $action($client, $request->validatedAccount());

        return to_route('clients.show', $client);
    }

    /**
     * Update an existing social account.
     */
    public function update(UpdateSocialAccountRequest $request, SocialAccount $socialAccount, UpdateSocialAccountAction $action): RedirectResponse
    {
        $action($socialAccount, $request->validatedAccount());

        return to_route('clients.show', $socialAccount->client_id);
    }

    /**
     * Delete a social account.
     */
    public function destroy(SocialAccount $socialAccount): RedirectResponse
    {
        $this->authorize('delete', $socialAccount);

        $clientId = $socialAccount->client_id;

        $socialAccount->delete();

        return to_route('clients.show', $clientId);
    }

    /**
     * Start the vendor connect flow for a social account (spec §7, Step 1.3). Returns the
     * adapter-provided connect URL as JSON (rather than redirecting the current browser tab/
     * window there) so the frontend can open it in a separate tab/popup, keeping Graphy itself
     * available and letting it poll for the connection status once that tab is closed.
     */
    public function connect(Request $request, SocialAccount $socialAccount, ConnectSocialAccountAction $action): JsonResponse
    {
        $this->authorize('update', $socialAccount);

        try {
            $url = $action($socialAccount, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Handle the vendor's connect-flow callback (spec §7, Step 1.3), persisting the external
     * profile id/connection status via the bound adapter. Renders a small standalone page (not an
     * Inertia page) that notifies the opening Graphy tab via `postMessage` and auto-closes itself,
     * since this callback is opened by `connect()` in a separate tab/popup rather than the user's
     * main Graphy tab.
     */
    public function callback(Request $request, PublishAdapter $adapter, ConnectStateStore $states, RecordClientConnectionAction $record): Response
    {
        // Step I-1.6: the callback is unauthenticated, so it must carry the signed, single-use
        // state issued by `ConnectSocialAccountAction` for this very account.
        $payload = $states->consume($request->query('state'), ConnectSocialAccountAction::STATE_FLOW);

        if ($payload === null || (string) $payload['social_account_id'] !== (string) $request->query('social_account_id')) {
            Log::warning('Rejected Upload-Post connect callback: missing, invalid or reused state', [
                'social_account_id' => $request->query('social_account_id'),
                'ip' => $request->ip(),
            ]);

            return response()->view('social-accounts.connected', [
                'connected' => false,
                'clientUrl' => route('dashboard'),
            ], Response::HTTP_FORBIDDEN);
        }

        $adapter->handleCallback($request->query());

        $socialAccount = SocialAccount::query()->withoutOrgScope()->find($payload['social_account_id']);
        $via = $payload['via'] ?? null;

        // Step M-2.8: a client connected it themselves — record it and tell the agency.
        if ($socialAccount !== null && in_array($via, ['invite', 'portal'], true)) {
            $record(
                $socialAccount,
                isset($payload['connect_invite_id']) ? ConnectInvite::query()->withoutOrgScope()->find($payload['connect_invite_id']) : null,
                $payload['user_id'] !== null ? User::query()->find($payload['user_id']) : null,
            );
        }

        return response()->view('social-accounts.connected', [
            'connected' => (bool) $socialAccount,
            'clientUrl' => match (true) {
                $socialAccount === null => route('dashboard'),
                $via === 'invite' => null,
                $via === 'portal' => route('portal.dashboard'),
                default => route('clients.show', $socialAccount->client_id),
            },
        ]);
    }
}
