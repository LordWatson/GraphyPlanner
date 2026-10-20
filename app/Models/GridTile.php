<?php

namespace App\Models;

use App\Enums\CampaignColour;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\GridTileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Phase 18 — one slot of a grid plan. `position` is its display order (1 = top-left = newest);
 * it publishes in reverse order. Its media (in carousel order) carries the same framing columns as
 * `post_assets`, so the grid preview and the publisher cut every image identically. Once turned
 * into a post (Step 18.9) `post_id` links it; deleting that post frees the tile again.
 *
 * @property int $id
 * @property int $grid_plan_id
 * @property int $position
 * @property GridTileKind $kind
 * @property MediaRatio $format
 * @property int|null $cover_asset_id
 * @property CampaignColour|null $placeholder_colour
 * @property string|null $team_note
 * @property string|null $caption_idea
 * @property Carbon|null $planned_local_date
 * @property string|null $planned_local_time
 * @property string|null $split_group_id
 * @property int|null $post_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'grid_plan_id', 'position', 'kind', 'format', 'cover_asset_id', 'placeholder_colour', 'team_note',
    'caption_idea', 'planned_local_date', 'planned_local_time', 'split_group_id', 'post_id',
])]
class GridTile extends Model
{
    /** @use HasFactory<GridTileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => GridTileKind::class,
            'format' => MediaRatio::class,
            'placeholder_colour' => CampaignColour::class,
            'planned_local_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<GridPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(GridPlan::class, 'grid_plan_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * The tile's media in carousel order, with each image's framing on the pivot.
     *
     * @return BelongsToMany<Asset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'grid_tile_assets')
            ->withoutGlobalScope(OrganizationScope::class)
            ->withPivot(['crop_x', 'crop_y', 'crop_zoom', 'alt_text', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('grid_tile_assets.id');
    }

    /**
     * A Reel's cover image.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'cover_asset_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * The post this tile became (Step 18.9), if any.
     *
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
