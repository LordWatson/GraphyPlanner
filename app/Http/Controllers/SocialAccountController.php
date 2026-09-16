<?php

namespace App\Http\Controllers;

use App\Actions\SocialAccounts\ConnectSocialAccountAction;
use App\Actions\SocialAccounts\CreateSocialAccountAction;
use App\Actions\SocialAccounts\UpdateSocialAccountAction;
use App\Contracts\PublishAdapter;
use App\Http\Requests\StoreSocialAccountRequest;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class SocialAccountController extends Controller
{
    /**
     * Create a new social account for the given client.
     */
    public function store(StoreSocialAccountRequest $request, Client $client, CreateSocialAccountAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.show', $client);
    }

    /**
     * Update an existing social account.
     */
    public function update(UpdateSocialAccountRequest $request, SocialAccount $socialAccount, UpdateSocialAccountAction $action): RedirectResponse
    {
        $action($socialAccount, $request->validated());

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
    public function connect(SocialAccount $socialAccount, ConnectSocialAccountAction $action): JsonResponse
    {
        $this->authorize('update', $socialAccount);

        try {
            $url = $action($socialAccount);
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
    public function callback(Request $request, PublishAdapter $adapter): View
    {
        $adapter->handleCallback($request->query());

        $socialAccount = SocialAccount::find($request->query('social_account_id'));

        return view('social-accounts.connected', [
            'connected' => (bool) $socialAccount,
            'clientUrl' => $socialAccount ? route('clients.show', $socialAccount->client_id) : route('dashboard'),
        ]);
    }
}
