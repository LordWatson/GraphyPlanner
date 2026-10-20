<?php

namespace App\Actions\Grids;

use App\Actions\Posts\UpdatePostAction;
use App\Enums\AssetType;
use App\Enums\GridTileKind;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\GridTile;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use App\Services\Grids\GridPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ResolveGridDriftAction
{
    public function __construct(
        private readonly UpdatePostAction $updatePost,
        private readonly GridPlanChanges $changes = new GridPlanChanges,
    ) {}

    /**
     * Step 18.11 — "Update the tile from the post": take the post's current media, framing and
     * format into the tile. That changes the approved look, so the grid reopens to `draft`.
     */
    public function tileFromPost(GridTile $tile, User $user): GridTile
    {
        $post = $tile->post ?? throw ValidationException::withMessages(['tile' => __('This tile has no post yet.')]);

        DB::transaction(function () use ($tile, $user, $post) {
            $plan = $this->changes->lock($tile->plan);
            $post->load('assets');

            $tile->update([
                'format' => $tile->kind === GridTileKind::Reel ? $tile->format : $post->mediaRatioFor(Platform::Instagram),
                'cover_asset_id' => $tile->kind === GridTileKind::Reel ? $post->cover_asset_id : $tile->cover_asset_id,
            ]);

            $tile->assets()->sync($post->assets->values()->mapWithKeys(fn (Asset $asset, int $order) => [$asset->id => GridPlanService::pivotRow($asset, $order)])->all());

            $this->changes->record($plan, $user, 'tile_updated_from_post');
        });

        Log::info('Grid tile updated from its post', ['grid_tile_id' => $tile->id, 'post_id' => $post->id, 'user_id' => $user->id]);

        return $tile->refresh();
    }

    /**
     * Step 18.11 — "Put the post back": restore the approved look onto the post (media, order,
     * framing, alt text, format, Reel cover) through `UpdatePostAction`, so a post the vendor holds
     * is recalled and re-sent with the right media.
     */
    public function postFromTile(GridTile $tile, User $user): void
    {
        $post = $tile->post ?? throw ValidationException::withMessages(['tile' => __('This tile has no post yet.')]);

        if (! $user->can('update', $post)) {
            throw ValidationException::withMessages(['tile' => __('That post is locked right now (it’s scheduled or out). Unschedule it first.')]);
        }

        $tile->load('assets');
        $assets = $tile->kind === GridTileKind::Reel
            ? $tile->assets->filter(fn (Asset $asset) => $asset->type === AssetType::Video)->take(1)
            : $tile->assets;

        ($this->updatePost)($post, [
            'asset_ids' => $assets->pluck('id')->values()->all(),
            'asset_crops' => $assets->mapWithKeys(function (Asset $asset) {
                $row = GridPlanService::pivotRow($asset, 0);

                return [$asset->id => ['x' => $row['crop_x'], 'y' => $row['crop_y'], 'zoom' => $row['crop_zoom']]];
            })->all(),
            'asset_alt_texts' => $assets->mapWithKeys(fn (Asset $asset) => [$asset->id => GridPlanService::pivotRow($asset, 0)['alt_text']])->all(),
            'media_format' => $tile->kind === GridTileKind::Reel ? $post->media_format : [...($post->media_format ?? []), Platform::Instagram->value => $tile->format->value],
            'cover_asset_id' => $tile->kind === GridTileKind::Reel ? $tile->cover_asset_id : $post->cover_asset_id,
        ]);

        Log::info('Grid post restored from its tile', ['grid_tile_id' => $tile->id, 'post_id' => $post->id, 'user_id' => $user->id]);
    }
}
