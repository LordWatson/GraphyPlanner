<?php

namespace App\Http\Controllers;

use App\Actions\SocialAccounts\CreateSocialAccountAction;
use App\Actions\SocialAccounts\UpdateSocialAccountAction;
use App\Http\Requests\StoreSocialAccountRequest;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;

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
}
