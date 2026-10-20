<?php

namespace App\Http\Controllers\Grids;

use App\Actions\Grids\GridAiAssist;
use App\Enums\ImageVariantStatus;
use App\Http\Controllers\Controller;
use App\Models\AiImageGeneration;
use App\Models\AiImageVariant;
use App\Models\GridPlan;
use App\Models\GridTile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Step 18.12 — AI suggestions for a grid, answered as JSON for the planner to show. Nothing is
 * saved here: "Use" on a caption idea saves the tile through the normal tile update.
 */
class GridAiController extends Controller
{
    public function captions(Request $request, GridPlan $grid, GridAiAssist $assist): JsonResponse
    {
        $this->authorize('useAi', $grid);

        return response()->json(['suggestions' => (object) $assist->suggestCaptions($grid, $request->user())]);
    }

    /**
     * "Fill this slot": start a 3:4 Image Studio set for a placeholder; the planner polls
     * `ai-image-generations.show` for it.
     */
    public function fillSlot(Request $request, GridPlan $grid, GridTile $tile, GridAiAssist $assist): JsonResponse
    {
        $this->authorize('useAi', $grid);
        $this->authorize('update', $grid);
        $this->authorize('generate', [AiImageGeneration::class, $grid->client]);
        $validated = $request->validate(['request_id' => ['required', 'uuid']]);

        $generation = $assist->fillSlot($tile, $request->user(), (string) $validated['request_id']);

        return response()->json(['generation_id' => $generation->id], 202);
    }

    public function useVariant(Request $request, GridPlan $grid, GridTile $tile, GridAiAssist $assist): RedirectResponse
    {
        $this->authorize('update', $grid);
        $validated = $request->validate([
            'variant_id' => ['required', 'integer', Rule::exists('ai_image_variants', 'id')
                ->where('status', ImageVariantStatus::Ready->value)
                ->whereNotNull('asset_id')
                ->where(fn ($query) => $query->whereIn('generation_id', fn ($generations) => $generations->select('id')->from('ai_image_generations')->where('client_id', $grid->client_id)))],
        ]);

        $assist->useVariant($tile, $request->user(), AiImageVariant::query()->findOrFail((int) $validated['variant_id']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Slot filled. Frame it if you like.')]);

        return to_route('grids.edit', ['grid' => $grid, 'tile' => $tile->id]);
    }

    public function balance(Request $request, GridPlan $grid, GridAiAssist $assist): JsonResponse
    {
        $this->authorize('useAi', $grid);

        return response()->json(['notes' => $assist->checkBalance($grid, $request->user())]);
    }
}
