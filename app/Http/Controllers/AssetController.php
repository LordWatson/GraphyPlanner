<?php

namespace App\Http\Controllers;

use App\Actions\Assets\CreateAssetAction;
use App\Actions\Assets\DeleteAssetAction;
use App\Actions\Assets\SearchClientAssetsAction;
use App\Enums\AssetType;
use App\Http\Requests\SearchAssetsRequest;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AssetController extends Controller
{
    /**
     * A page of the client's asset library as JSON, filtered by filename search and type — the
     * post editor's "Browse library" dialog and the client Assets tab page through this instead of
     * preloading every asset. `can.delete` lets the Assets tab offer removal per asset.
     */
    public function index(SearchAssetsRequest $request, Client $client, SearchClientAssetsAction $action): JsonResponse
    {
        $type = $request->validated('type');

        $assets = $action(
            $client,
            $request->validated('search'),
            $type !== null ? AssetType::from($type) : null,
            (int) ($request->validated('page') ?? 1),
            // Step C-4.1: narrow to one campaign's kit.
            $request->validated('campaign') !== null ? (int) $request->validated('campaign') : null,
        );

        return response()->json([
            'data' => collect($assets->items())->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'url' => $asset->url,
                'original_filename' => $asset->original_filename,
                'type' => $asset->type?->value,
                'is_ai_generated' => $asset->is_ai_generated,
                'campaign_name' => $asset->campaign?->name,
                'created_at' => $asset->created_at?->toIso8601String(),
                'can' => [
                    'delete' => $request->user()->can('delete', $asset),
                ],
            ])->values(),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'last_page' => $assets->lastPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
            ],
        ]);
    }

    /**
     * Upload or link a new asset for the given client.
     *
     * Callers that want to stay on their current page (e.g. the post editor's inline "upload
     * asset" widget, added so users don't have to flick over to the client's Assets tab) send
     * `Accept: application/json` and get the created asset back as JSON instead of being
     * redirected to `clients.media`.
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

        return to_route('clients.media', $client);
    }

    /**
     * Delete an asset (removing its stored file, if any). JSON callers (the Assets tab's library
     * dialog) get a 204 so the dialog can refresh in place instead of following a redirect.
     */
    public function destroy(Request $request, Asset $asset, DeleteAssetAction $action): RedirectResponse|Response
    {
        $this->authorize('delete', $asset);

        $clientId = $asset->client_id;

        $action($asset);

        if ($request->wantsJson()) {
            return response()->noContent();
        }

        return to_route('clients.media', $clientId);
    }
}
