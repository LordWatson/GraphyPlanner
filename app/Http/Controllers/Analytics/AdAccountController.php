<?php

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\GetClientAdsAction;
use App\Actions\Analytics\SyncAdAccountMetricsAction;
use App\Contracts\AdsSource;
use App\Contracts\Entitlements;
use App\Enums\ConnectionStatus;
use App\Enums\Feature;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\AdAccount;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Step GTM-5.2 — the ad accounts a brand's spend is reported from, managed from its analytics page.
 *
 * **Nothing here writes to an ad platform.** It records which account to *read*, reads one now, or
 * stops reading it. The contract it leans on has no write method at all
 * ({@see AdsSource}).
 *
 * Writes need the client-update ability: what a client's spend report says is an account decision.
 */
class AdAccountController extends Controller
{
    public function store(Request $request, Client $client, SyncAdAccountMetricsAction $sync): RedirectResponse
    {
        $this->authorize('update', $client);

        $organization = $client->organization;

        if ($organization !== null) {
            app(Entitlements::class)->ensure($organization, Feature::AdsReporting);
        }

        $platforms = array_map(fn (Platform $platform) => $platform->value, GetClientAdsAction::supportedPlatforms());

        $validated = $request->validate([
            'platform' => ['required', Rule::in($platforms)],
            'external_account_id' => ['required', 'string', 'max:191', 'regex:/^(act_)?[0-9]+$/'],
            'name' => ['nullable', 'string', 'max:191'],
        ], [
            'platform.in' => __('Only Meta ad accounts can be reported on today.'),
            'external_account_id.regex' => __('A Meta ad account id is digits, optionally prefixed with act_.'),
        ]);

        $external = ltrim($validated['external_account_id'], 'act_');

        $taken = AdAccount::query()
            ->withoutOrgScope()
            ->where('org_id', $client->org_id)
            ->where('platform', $validated['platform'])
            ->where('external_account_id', $external)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'external_account_id' => __('That ad account is already being reported on.'),
            ]);
        }

        $account = AdAccount::create([
            'org_id' => $client->org_id,
            'client_id' => $client->id,
            'platform' => $validated['platform'],
            'external_account_id' => $external,
            'name' => $validated['name'] ?? null,
            // Optimistic: the first read is what proves we can actually see it, and flips this to
            // disconnected with the reason if we can't.
            'connection_status' => ConnectionStatus::Connected,
        ]);

        $result = $sync($account);

        Inertia::flash('toast', $result === 'failed'
            ? ['type' => 'error', 'message' => __('Added, but no figures came back — check our access to that ad account.')]
            : ['type' => 'success', 'message' => __('Reporting on it.')]);

        return back();
    }

    public function sync(Client $client, AdAccount $adAccount, SyncAdAccountMetricsAction $sync): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($adAccount->client_id === $client->id, 404);

        // A retry re-connects first: an account that was marked disconnected by a failed read
        // should be readable again once whatever broke is fixed.
        $adAccount->forceFill(['connection_status' => ConnectionStatus::Connected])->saveQuietly();

        $result = $sync($adAccount);

        Inertia::flash('toast', $result === 'failed'
            ? ['type' => 'error', 'message' => $adAccount->refresh()->metrics_error ?? __('No figures came back.')]
            : ['type' => 'success', 'message' => __('Updated.')]);

        return back();
    }

    public function destroy(Client $client, AdAccount $adAccount): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($adAccount->client_id === $client->id, 404);

        $adAccount->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stopped reporting on it.')]);

        return back();
    }
}
