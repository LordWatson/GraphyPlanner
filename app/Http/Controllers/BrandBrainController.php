<?php

namespace App\Http\Controllers;

use App\Actions\BrandBrains\SyncBrandReferenceAssetsAction;
use App\Actions\BrandBrains\UpdateBrandBrainAction;
use App\Actions\BrandBrains\UploadBrandPersonaAction;
use App\Enums\ReferenceImageRole;
use App\Http\Requests\UpdateBrandBrainRequest;
use App\Http\Requests\UpdateBrandReferencesRequest;
use App\Http\Requests\UploadBrandPersonaRequest;
use App\Models\BrandBrain;
use App\Models\Client;
use App\Policies\BrandBrainPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandBrainController extends Controller
{
    /**
     * Show the brand brain editor for the given client.
     */
    public function edit(Request $request, Client $client, BrandBrainPolicy $policy): Response
    {
        if (! $policy->viewForClient($request->user(), $client)) {
            abort(403);
        }

        $brandBrain = $client->brandBrain ?? new BrandBrain(['client_id' => $client->id]);

        return Inertia::render('brand-brain/edit', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
            ],
            'brandBrain' => [
                'voice' => $brandBrain->voice,
                'audience' => $brandBrain->audience,
                'offer' => $brandBrain->offer,
                'visual' => $brandBrain->visual,
                'music_policy' => $brandBrain->music_policy,
                'hashtag_policy' => $brandBrain->hashtag_policy,
                'content_pillars' => $brandBrain->content_pillars,
                'persona_url' => $brandBrain->persona_url,
                'persona_original_filename' => $brandBrain->persona_original_filename,
            ],
            // Step 16.4: visual references for the AI Image Studio.
            'references' => $brandBrain->exists
                ? $brandBrain->referenceAssets()->with('asset')->get()
                    ->filter(fn ($reference) => $reference->asset !== null)
                    ->map(fn ($reference) => [
                        'asset_id' => $reference->asset_id,
                        'role' => $reference->role->value,
                        'url' => $reference->asset?->url,
                        'original_filename' => $reference->asset?->original_filename,
                    ])->values()
                : [],
            'referenceRoles' => array_map(fn (ReferenceImageRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
            ], ReferenceImageRole::brandRoles()),
            'maxReferences' => (int) config('ai.images.max_references', 8),
            'can' => [
                'update' => $policy->update($request->user(), $client),
            ],
        ]);
    }

    /**
     * Create or update the brand brain for the given client.
     */
    public function update(UpdateBrandBrainRequest $request, Client $client, UpdateBrandBrainAction $action): RedirectResponse
    {
        $action($client, $request->validated());

        return to_route('clients.brand-brain.edit', $client);
    }

    /**
     * Upload (or overwrite) the Brand Persona PDF for the given client.
     */
    public function uploadPersona(UploadBrandPersonaRequest $request, Client $client, UploadBrandPersonaAction $action): RedirectResponse
    {
        $action($client, $request->file('persona'));

        return to_route('clients.brand-brain.edit', $client);
    }

    /**
     * Save the client's visual references (Step 16.4): ordered, role-tagged client images.
     */
    public function updateReferences(UpdateBrandReferencesRequest $request, Client $client, SyncBrandReferenceAssetsAction $action): RedirectResponse
    {
        $action($client, $request->user(), $request->validated('references'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Visual references saved.')]);

        return to_route('clients.brand-brain.edit', $client);
    }
}
