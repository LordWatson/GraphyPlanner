<?php

namespace App\Http\Controllers\Analytics;

use App\Actions\Benchmarks\AddTrackedProfileAction;
use App\Actions\Benchmarks\SyncTrackedProfileAction;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\TrackedProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Step GTM-4.1 — the competitor profiles a brand is measured against, managed from that brand's
 * analytics page. Thin: one Action per write, and the plan checks live in the Action.
 *
 * Writes need the client-**update** ability, not just view: who a brand is compared against is an
 * account decision, and every profile costs a nightly read.
 */
class BenchmarkController extends Controller
{
    public function store(Request $request, Client $client, AddTrackedProfileAction $action): RedirectResponse
    {
        $this->authorize('update', $client);

        $validated = $request->validate([
            'platform' => ['required', Rule::enum(Platform::class)],
            'handle' => ['required', 'string', 'max:120', 'regex:/^@?[A-Za-z0-9._-]+$/'],
            'label' => ['nullable', 'string', 'max:120'],
        ], [
            'handle.regex' => __('A handle is letters, numbers, dots, dashes and underscores.'),
        ]);

        $profile = $action(
            $client,
            Platform::from($validated['platform']),
            $validated['handle'],
            $validated['label'] ?? null,
            $request->user(),
        );

        Inertia::flash('toast', $profile->metrics()->exists()
            ? ['type' => 'success', 'message' => __('Tracking it, with its first reading in.')]
            : ['type' => 'info', 'message' => __('Tracking it, but no public figures came back — we will try again overnight.')]);

        return back();
    }

    /**
     * Read one profile now rather than waiting for the nightly sweep, so somebody adding a handle
     * can see straight away whether we can get anything for it.
     */
    public function sync(Client $client, TrackedProfile $profile, SyncTrackedProfileAction $action): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($profile->client_id === $client->id, 404);

        $result = $action($profile);

        Inertia::flash('toast', $result === 'stored'
            ? ['type' => 'success', 'message' => __('Updated.')]
            : ['type' => 'error', 'message' => __('No public figures were available for that profile.')]);

        return back();
    }

    public function destroy(Client $client, TrackedProfile $profile): RedirectResponse
    {
        $this->authorize('update', $client);
        abort_unless($profile->client_id === $client->id, 404);

        $profile->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stopped tracking it.')]);

        return back();
    }
}
