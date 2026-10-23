<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\SuggestCaptionsFromMediaAction;
use App\Actions\Assets\CreateAssetAction;
use App\Actions\Assets\SearchClientAssetsAction;
use App\Enums\AssetType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchAssetsRequest;
use App\Http\Requests\StoreAssetRequest;
use App\Models\Asset;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 — the business shell's "Media" library. The page browses the brand's assets through
 * `library` (JSON paging, the same {@see SearchClientAssetsAction} the agency Assets tab uses) and
 * uploads through `store`; deletion reuses the shared `assets.destroy` (JSON). The brand is
 * implicit, resolved from {@see CurrentWorkspace}.
 */
class MediaController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace): Response
    {
        $brand = $workspace->brand();

        $this->authorize('viewAny', [Asset::class, $brand]);

        return Inertia::render('business/media/index', [
            'assetTypes' => array_map(
                fn (AssetType $type) => ['value' => $type->value, 'label' => $type->label()],
                AssetType::cases(),
            ),
            'aiEnabled' => (bool) config('ai.enabled', false),
            'can' => [
                'create' => $request->user()->can('create', [Asset::class, $brand]),
                'suggestCaptions' => $this->canSuggestCaptions($request),
            ],
        ]);
    }

    /**
     * Step B-6.2 — suggest captions for one of the brand's photos (the "Caption from photo" button
     * on an image tile). Thin: it checks the AI gate and the asset belongs to the brand, then hands
     * off to {@see SuggestCaptionsFromMediaAction}, which never throws — an empty list is a valid,
     * handled result the page shows as "nothing came back".
     */
    public function captions(Request $request, Asset $asset, CurrentWorkspace $workspace, SuggestCaptionsFromMediaAction $action): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);

        $brand = $workspace->brand();
        $this->authorize('view', $brand);
        abort_unless($this->canSuggestCaptions($request), 403);
        abort_unless($asset->client_id === $brand->id, 404);

        return response()->json(['suggestions' => $action($brand, $asset)]);
    }

    /**
     * Captions are a content-creation aid, so Owners, Managers (Strategist) and Creators (Designer)
     * may use them; Viewers (and client reviewers) may not.
     */
    private function canSuggestCaptions(Request $request): bool
    {
        return in_array($request->user()->role, [Role::Owner, Role::Strategist, Role::Designer], true);
    }

    /**
     * A page of the brand's media as JSON (the library grid's data source), the same presenter
     * shape the agency Assets dialog uses, so the two stay identical.
     */
    public function library(SearchAssetsRequest $request, CurrentWorkspace $workspace, SearchClientAssetsAction $action): JsonResponse
    {
        $brand = $workspace->brand();
        $type = $request->validated('type');

        $assets = $action(
            $brand,
            $request->validated('search'),
            $type !== null ? AssetType::from($type) : null,
            (int) ($request->validated('page') ?? 1),
            $request->validated('campaign') !== null ? (int) $request->validated('campaign') : null,
        );

        return response()->json([
            'data' => collect($assets->items())->map(fn (Asset $asset) => [
                'id' => $asset->id,
                'url' => $asset->url,
                'thumbnail_url' => $asset->thumbnailUrl(),
                'original_filename' => $asset->original_filename,
                'type' => $asset->type?->value,
                'is_ai_generated' => $asset->is_ai_generated,
                'created_at' => $asset->created_at?->toIso8601String(),
                'can' => ['delete' => $request->user()->can('delete', $asset)],
            ])->values(),
            'meta' => [
                'current_page' => $assets->currentPage(),
                'last_page' => $assets->lastPage(),
                'per_page' => $assets->perPage(),
                'total' => $assets->total(),
                'from' => $assets->firstItem(),
                'to' => $assets->lastItem(),
            ],
        ]);
    }

    public function store(StoreAssetRequest $request, CurrentWorkspace $workspace, CreateAssetAction $action): RedirectResponse|JsonResponse
    {
        $asset = $action($workspace->brand(), $request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'asset' => [
                    'id' => $asset->id,
                    'url' => $asset->url,
                    'thumbnail_url' => $asset->thumbnailUrl(),
                    'original_filename' => $asset->original_filename,
                    'type' => $asset->type?->value,
                ],
            ], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media added.')]);

        return to_route('business.media.index');
    }
}
