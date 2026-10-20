<?php

namespace App\Actions\Grids;

use App\Enums\AssetType;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\Post;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use App\Services\Grids\GridPlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddOutsidePostToGridAction
{
    public function __construct(
        private readonly GridPlanService $grids = new GridPlanService,
        private readonly GridPlanChanges $changes = new GridPlanChanges,
    ) {}

    /**
     * Step 18.11 — "Add to plan": pull a post that lands inside the grid into the plan as a tile at
     * the slot it really takes, linked to the post (its media and framing copied). The layout
     * changes, so an approved grid reopens to `draft`.
     */
    public function __invoke(GridPlan $plan, Post $post, User $user): GridTile
    {
        $layout = $this->grids->layout($plan);
        $slot = collect($layout->outside())->firstWhere('id', $post->id);

        if ($slot === null) {
            throw ValidationException::withMessages(['post' => __('That post doesn’t land inside this grid.')]);
        }

        // Its position among the tiles: one after every tile shown before it.
        $position = collect($layout->slots)->filter(fn (array $entry) => $entry['kind'] === 'tile' && $entry['slot'] < $slot['slot'])->count() + 1;

        return DB::transaction(function () use ($plan, $post, $user, $position) {
            $locked = $this->changes->lock($plan);
            $existing = GridPlanChanges::orderedIds($locked);
            $post->load(['assets', 'targets']);

            $video = $post->assets->first(fn (Asset $asset) => $asset->type === AssetType::Video);
            $target = $post->targets->firstWhere('social_account_id', $locked->social_account_id);
            $kind = match (true) {
                $video !== null => GridTileKind::Reel,
                $post->assets->count() > 1 => GridTileKind::Carousel,
                default => GridTileKind::Image,
            };

            /** @var GridTile $tile */
            $tile = $locked->tiles()->create([
                'position' => 20000,
                'kind' => $kind,
                'format' => $kind === GridTileKind::Reel ? MediaRatio::Vertical : $post->mediaRatioFor(Platform::Instagram),
                'cover_asset_id' => $kind === GridTileKind::Reel ? $post->cover_asset_id : null,
                'caption_idea' => $post->master_caption,
                'planned_local_date' => $target?->scheduled_local_date?->toDateString(),
                'planned_local_time' => $target?->scheduled_local_time,
                'post_id' => $post->id,
            ]);

            $tile->assets()->sync($post->assets->values()->mapWithKeys(fn (Asset $asset, int $order) => [$asset->id => GridPlanService::pivotRow($asset, $order)])->all());

            GridPlanChanges::writePositions($locked, [
                ...array_slice($existing, 0, $position - 1),
                $tile->id,
                ...array_slice($existing, $position - 1),
            ]);

            $this->changes->record($locked, $user, 'outside_post_added');

            return $tile->refresh();
        });
    }
}
