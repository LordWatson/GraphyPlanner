<?php

namespace App\Http\Controllers;

use App\Actions\Assets\CreateAssetAction;
use App\Actions\Assets\DeleteAssetAction;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AssetController extends Controller
{
    /**
     * Upload or link a new asset for the given client.
     *
     * Callers that want to stay on their current page (e.g. the post editor's inline "upload
     * asset" widget, added so users don't have to flick over to the client's Assets tab) send
     * `Accept: application/json` and get the created asset back as JSON instead of being
     * redirected to `clients.show`.
     */
    public function store(StoreAssetRequest $request, Client $client, CreateAssetAction $action): RedirectResponse|JsonResponse
    {
        $asset = $action($client, $request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'asset' => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                ],
            ], 201);
        }

        return to_route('clients.show', $client);
    }

    /**
     * Delete an asset (removing its stored file, if any).
     */
    public function destroy(Asset $asset, DeleteAssetAction $action): RedirectResponse
    {
        $this->authorize('delete', $asset);

        $clientId = $asset->client_id;

        $action($asset);

        return to_route('clients.show', $clientId);
    }
}
