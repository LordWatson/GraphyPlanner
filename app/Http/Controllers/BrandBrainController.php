<?php

namespace App\Http\Controllers;

use App\Actions\BrandBrains\UpdateBrandBrainAction;
use App\Http\Requests\UpdateBrandBrainRequest;
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
            ],
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
}
