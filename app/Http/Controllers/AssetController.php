<?php

namespace App\Http\Controllers;

use App\Actions\Assets\CreateAssetAction;
use App\Actions\Assets\DeleteAssetAction;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;

class AssetController extends Controller
{
    /**
     * Upload or link a new asset for the given client.
     */
    public function store(StoreAssetRequest $request, Client $client, CreateAssetAction $action): RedirectResponse
    {
        $action($client, $request->user(), $request->validated());

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
