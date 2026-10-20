<?php

namespace App\Support\Grids;

use App\Enums\ImageVariantStatus;
use App\Models\AiImageVariant;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Services\Grids\GridPlanService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 18 — the small shapes around the planner: a plan's card on the Grid tab (and the
 * campaign workspace), and the Image Studio images a grid can use.
 */
final class GridLibrary
{
    /**
     * A grid plan as a card: its status, account, progress and the first nine tiles as the grid
     * shows them. Expects `socialAccount`, `campaign`, `tiles.assets`, `tiles.coverAsset` and
     * `tiles.post.targets` loaded.
     *
     * @return array<string, mixed>
     */
    public static function summary(GridPlan $plan, GridPlanService $grids): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'status' => $plan->status->value,
            'status_label' => $plan->status->label(),
            'handle' => $plan->socialAccount->handle,
            'campaign' => $plan->campaign !== null ? ['id' => $plan->campaign->id, 'name' => $plan->campaign->name, 'colour' => $plan->campaign->colour?->value] : null,
            'tile_count' => $plan->tiles->count(),
            'progress' => $grids->progress($plan),
            'updated_at' => $plan->updated_at?->toIso8601String(),
            'preview' => $plan->tiles->take(9)->map(function (GridTile $tile) use ($grids) {
                $asset = $grids->thumbnailAsset($tile);

                return [
                    'id' => $tile->id,
                    'kind' => $tile->kind->value,
                    'placeholder_colour' => $tile->placeholder_colour?->value,
                    'thumbnail_url' => $asset?->thumbnailUrl(),
                    'frame' => $grids->frame($tile)?->toArray(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Step 18.9 — the post editor's "From grid: {name} · Tile 4 · Goes out 3rd" chip, for a post
     * made from a grid tile; null otherwise.
     *
     * @return array{id: int, name: string, status: string, tile_id: int, tile_position: int, sequence: int|null, tile_count: int}|null
     */
    public static function postChip(Post $post, GridPlanService $grids): ?array
    {
        $post->loadMissing('gridTile.plan');
        $tile = $post->gridTile;
        $plan = $tile?->plan;

        if ($tile === null || $plan === null) {
            return null;
        }

        $layout = $grids->layout($plan);

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'status' => $plan->status->value,
            'tile_id' => $tile->id,
            'tile_position' => $tile->position,
            'sequence' => $layout->tile($tile->id)['sequence'] ?? null,
            'tile_count' => $plan->tiles->count(),
        ];
    }

    /**
     * The client's finished Image Studio images someone picked (`selected`), newest first — the
     * planner's "From Image Studio" source.
     *
     * @return list<array<string, mixed>>
     */
    public static function studioImages(GridPlan $plan): array
    {
        return array_values(AiImageVariant::query()
            ->whereNotNull('selected_at')
            ->whereNotNull('asset_id')
            ->where('status', ImageVariantStatus::Ready)
            ->whereHas('generation', fn (Builder $query) => $query->where('client_id', $plan->client_id))
            ->with('asset')
            ->latest('selected_at')
            ->limit(48)
            ->get()
            ->filter(fn (AiImageVariant $variant) => $variant->asset !== null)
            ->map(fn (AiImageVariant $variant) => [
                'variant_id' => $variant->id,
                'asset_id' => $variant->asset_id,
                'thumbnail_url' => $variant->asset?->thumbnailUrl(),
                'width' => $variant->asset?->width,
                'height' => $variant->asset?->height,
            ])
            ->all());
    }
}
