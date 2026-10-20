<?php

namespace App\Services\Grids;

use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;

/**
 * Step 18.11 — has a tile's post wandered from the look the client approved? Compares each
 * linked post's media (and order), framing and Instagram format with its tile. The planner shows
 * "Changed since the client approved the grid" and the post's checklist gets a soft `grid_match`
 * item; the fix is "Update the tile from the post" or "Put the post back".
 */
class GridDriftService
{
    public function __construct(private readonly GridPlanService $grids = new GridPlanService) {}

    /**
     * @return array<int, list<string>> what changed, per drifted tile id
     */
    public function driftedTiles(GridPlan $plan): array
    {
        $this->grids->load($plan);
        $plan->tiles->each(fn (GridTile $tile) => $tile->post?->loadMissing('assets'));

        $drifted = [];

        foreach ($plan->tiles as $tile) {
            if ($tile->post === null) {
                continue;
            }

            $changes = $this->changes($plan, $tile, $tile->post);

            if ($changes !== []) {
                $drifted[$tile->id] = $changes;
            }
        }

        return $drifted;
    }

    /**
     * @return list<string>
     */
    public function changes(GridPlan $plan, GridTile $tile, Post $post): array
    {
        $post->loadMissing(['assets', 'targets']);
        $tile->loadMissing('assets');
        $changes = [];

        $tileMedia = $tile->assets->map(fn (Asset $asset) => $asset->id)->values()->all();
        $postMedia = $post->assets->map(fn (Asset $asset) => $asset->id)->values()->all();

        if ($tileMedia !== $postMedia) {
            $changes[] = 'The images or their order changed.';
        } elseif ($this->framing(array_values($tile->assets->all())) !== $this->framing(array_values($post->assets->all()))) {
            $changes[] = 'An image was reframed.';
        }

        $target = $post->targets->firstWhere('social_account_id', $plan->social_account_id);

        if ($tile->kind === GridTileKind::Reel) {
            if ($target !== null && $target->format !== null && $target->format !== 'reel') {
                $changes[] = 'It no longer goes out as a Reel.';
            }
        } elseif ($post->mediaRatioFor(Platform::Instagram) !== $tile->format && ! ($tile->format === MediaRatio::Original && $post->media_format === null)) {
            $changes[] = 'The format changed to '.$post->mediaRatioFor(Platform::Instagram)->value.'.';
        }

        if ($tile->cover_asset_id !== null && $post->cover_asset_id !== $tile->cover_asset_id) {
            $changes[] = 'The Reel cover changed.';
        }

        return $changes;
    }

    /**
     * @param  list<Asset>  $assets
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function framing(array $assets): array
    {
        return array_map(function (Asset $asset) {
            [$x, $y, $zoom] = GridPlanService::pivotFraming($asset);

            return [round($x, 4), round($y, 4), round($zoom, 2)];
        }, $assets);
    }
}
