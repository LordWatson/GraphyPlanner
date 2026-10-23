<?php

namespace App\Http\Controllers;

use App\Actions\Assets\ImportDesignAssetAction;
use App\Enums\DesignImportProvider;
use App\Enums\Role;
use App\Exceptions\DesignImportReconnectRequired;
use App\Models\Asset;
use App\Models\Client;
use App\Models\DesignImportConnection;
use App\Services\DesignImports\DesignImportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Step M-5.9 — connect design/cloud sources (Canva, Google Drive, Dropbox) and import media from
 * them. OAuth tokens are stored encrypted on `DesignImportConnection` and never logged; a dead
 * connection is surfaced as a reconnect prompt, never a raw error.
 */
class DesignImportController extends Controller
{
    public function __construct(private DesignImportManager $manager) {}

    /** Begin the OAuth2 flow for a provider (Owner/Strategist only). */
    public function connect(Request $request, DesignImportProvider $provider): RedirectResponse
    {
        $this->authorizeConnect($request);
        $source = $this->manager->enabledOrFail($provider);

        $state = Str::random(40);
        $request->session()->put('design_import_state', $state);

        return redirect()->away($source->authorizationUrl($this->redirectUri($provider), $state));
    }

    /** OAuth2 callback: exchange the code and store (encrypted) tokens. */
    public function callback(Request $request, DesignImportProvider $provider): RedirectResponse
    {
        $this->authorizeConnect($request);

        $expected = $request->session()->pull('design_import_state');

        if (blank($expected) || $request->query('state') !== $expected || blank($request->query('code'))) {
            return redirect()->route('clients.index')->with('toast', ['type' => 'error', 'message' => __('Connecting :provider was cancelled.', ['provider' => $provider->label()])]);
        }

        try {
            $tokens = $this->manager->enabledOrFail($provider)
                ->exchangeCode((string) $request->query('code'), $this->redirectUri($provider));
        } catch (\Throwable $e) {
            Log::warning('Design import connect failed', ['provider' => $provider->value, 'org_id' => $request->user()->org_id]);

            return redirect()->route('clients.index')->with('toast', ['type' => 'error', 'message' => __('We couldn’t connect :provider. Try again.', ['provider' => $provider->label()])]);
        }

        DesignImportConnection::query()->updateOrCreate(
            ['org_id' => $request->user()->org_id, 'provider' => $provider],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'expires_at' => $tokens['expires_at'] ?? null,
                'account_label' => $tokens['account_label'] ?? null,
                'created_by' => $request->user()->id,
                'revoked_at' => null,
            ],
        );

        return redirect()->route('clients.index')->with('toast', ['type' => 'success', 'message' => __(':provider connected.', ['provider' => $provider->label()])]);
    }

    /** List importable files for a client (JSON, lazy-loaded by the import dialog). */
    public function files(Request $request, Client $client): JsonResponse
    {
        $this->authorize('create', [Asset::class, $client]);

        $provider = DesignImportProvider::from((string) $request->query('provider'));
        $connection = $this->liveConnection($request, $provider);

        if ($connection === null) {
            return response()->json(['connected' => false, 'files' => []]);
        }

        try {
            $listing = $this->manager->for($provider)->listFiles($connection, $request->query('cursor'));
        } catch (DesignImportReconnectRequired) {
            return response()->json(['connected' => true, 'needs_reconnect' => true, 'files' => []], 409);
        }

        return response()->json([
            'connected' => true,
            'needs_reconnect' => false,
            'files' => array_map(fn ($file) => $file->toArray(), $listing->files),
            'next_cursor' => $listing->nextCursor,
        ]);
    }

    /** Import one file into the client's library (optionally into a campaign's kit). */
    public function import(Request $request, Client $client, ImportDesignAssetAction $import): RedirectResponse
    {
        $this->authorize('create', [Asset::class, $client]);

        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'file_id' => ['required', 'string'],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $client->id)],
        ]);

        $provider = DesignImportProvider::from($validated['provider']);
        $connection = $this->liveConnection($request, $provider);

        if ($connection === null) {
            return back()->with('toast', ['type' => 'error', 'message' => __('Connect :provider first.', ['provider' => $provider->label()])]);
        }

        try {
            $import($connection, $validated['file_id'], $client, $request->user(), $validated['campaign_id'] ?? null);
        } catch (DesignImportReconnectRequired) {
            return back()->with('toast', ['type' => 'error', 'message' => __('Your :provider connection needs reconnecting.', ['provider' => $provider->label()])]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Imported from :provider.', ['provider' => $provider->label()])]);
    }

    /** Revoke a connection (keeps the row for history). */
    public function disconnect(Request $request, DesignImportConnection $connection): RedirectResponse
    {
        $this->authorizeConnect($request);
        abort_unless($connection->org_id === $request->user()->org_id, 403);

        $connection->update(['revoked_at' => now(), 'access_token' => null, 'refresh_token' => null]);

        return back()->with('toast', ['type' => 'success', 'message' => __(':provider disconnected.', ['provider' => $connection->provider->label()])]);
    }

    private function liveConnection(Request $request, DesignImportProvider $provider): ?DesignImportConnection
    {
        return DesignImportConnection::query()
            ->where('org_id', $request->user()->org_id)
            ->where('provider', $provider)
            ->live()
            ->latest('id')
            ->first();
    }

    private function redirectUri(DesignImportProvider $provider): string
    {
        return route('design-imports.callback', ['provider' => $provider->value]);
    }

    private function authorizeConnect(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [Role::Owner, Role::Strategist], true), 403);
    }
}
