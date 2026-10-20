<?php

namespace App\Services\Grids;

use App\Enums\AssetType;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\PostTarget;
use App\Support\Grids\GridLayout;
use App\Support\Grids\GridLayoutTile;
use App\Support\Grids\GridOutsidePost;
use App\Support\Grids\GridTileFrame;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 18 — reads a grid plan's models into the layout core's inputs and the derived facts every
 * screen needs: when each tile goes out, the outside posts that land among the tiles (Step 18.11),
 * each tile's grid frame, the layout hash the client approves (Step 18.2) and the production
 * progress (never stored).
 *
 * Callers should load `tiles.assets`, `tiles.coverAsset`, `tiles.post.targets` and
 * `socialAccount` first; anything missing is loaded here.
 */
class GridPlanService
{
    /** Post statuses whose Instagram target is on its way to (or already on) the profile. */
    private const GOING_OUT = [PostStatus::Scheduled, PostStatus::Publishing, PostStatus::Published, PostStatus::PartiallyPublished];

    public function __construct(private readonly GridLayoutService $layouts = new GridLayoutService) {}

    public function load(GridPlan $plan): GridPlan
    {
        return $plan->loadMissing(['socialAccount', 'client', 'tiles.assets', 'tiles.coverAsset', 'tiles.post.targets.socialAccount']);
    }

    /**
     * The full layout: tiles in display order with outside posts in their real slots.
     *
     * @param  list<GridOutsidePost>|null  $outside  already-loaded outside posts (queried when null)
     */
    public function layout(GridPlan $plan, ?array $outside = null): GridLayout
    {
        $this->load($plan);

        $tiles = $plan->tiles->map(fn (GridTile $tile) => new GridLayoutTile(
            id: $tile->id,
            position: $tile->position,
            splitGroupId: $tile->split_group_id,
            publishAt: $this->publishAt($plan, $tile),
        ))->all();

        return $this->layouts->layout(array_values($tiles), $plan->pinned_count, $outside ?? $this->outsidePosts($plan));
    }

    /**
     * When a tile goes out (UTC): its post's Instagram time once it has a post with one, else its
     * planned local date and time in the account's timezone, else null.
     */
    public function publishAt(GridPlan $plan, GridTile $tile): ?CarbonImmutable
    {
        $target = $this->instagramTarget($plan, $tile);

        if ($target?->scheduled_at_utc !== null) {
            return CarbonImmutable::parse($target->scheduled_at_utc)->utc();
        }

        if ($tile->planned_local_date === null || blank($tile->planned_local_time)) {
            return null;
        }

        return CarbonImmutable::parse(
            $tile->planned_local_date->toDateString().' '.$tile->planned_local_time,
            $plan->socialAccount->timezone ?? 'UTC',
        )->utc();
    }

    /**
     * The linked post's target on the plan's Instagram account, if any.
     */
    public function instagramTarget(GridPlan $plan, GridTile $tile): ?PostTarget
    {
        if ($tile->post === null) {
            return null;
        }

        return $tile->post->targets->firstWhere('social_account_id', $plan->social_account_id);
    }

    /**
     * Step 18.11 — feed posts on the same account (not Stories, not this plan's own) going out or
     * already out between the plan's first and last dates. They take a real slot on the grid and
     * shift the tiles after them.
     *
     * @return list<GridOutsidePost>
     */
    public function outsidePosts(GridPlan $plan): array
    {
        $this->load($plan);

        $times = $plan->tiles->map(fn (GridTile $tile) => $this->publishAt($plan, $tile))->filter()->values();

        if ($times->isEmpty()) {
            return [];
        }

        $first = $times->min();
        $last = $times->max();
        $own = $plan->tiles->pluck('post_id')->filter()->values()->all();

        return array_values(PostTarget::query()
            ->where('social_account_id', $plan->social_account_id)
            ->whereBetween('scheduled_at_utc', [$first, $last])
            ->where(fn (Builder $query) => $query->whereNull('format')->orWhere('format', '!=', 'story'))
            ->whereNotIn('post_id', $own)
            ->whereHas('post', fn (Builder $post) => $post->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereIn('status', self::GOING_OUT))
            ->where('status', '!=', PostTargetStatus::Failed)
            ->orderBy('scheduled_at_utc')
            ->limit(60)
            ->get(['id', 'post_id', 'scheduled_at_utc'])
            ->map(fn (PostTarget $target) => new GridOutsidePost($target->post_id, CarbonImmutable::parse($target->scheduled_at_utc)))
            ->all());
    }

    /**
     * The grid thumbnail maths for a tile: its first image (a Reel's cover, or the video itself)
     * framed at the tile's format, then the 3:4 grid crop. Null for a tile with no media.
     */
    public function frame(GridTile $tile): ?GridTileFrame
    {
        $asset = $this->thumbnailAsset($tile);

        if ($asset === null) {
            return null;
        }

        [$x, $y, $zoom] = self::pivotFraming($asset);

        return GridTileFrame::compute($asset->width, $asset->height, $tile->format, $x, $y, $zoom);
    }

    /**
     * The image the grid shows for a tile: a Reel's cover when it has one, else its first item.
     */
    public function thumbnailAsset(GridTile $tile): ?Asset
    {
        if ($tile->coverAsset !== null) {
            return $tile->coverAsset;
        }

        return $tile->assets->first();
    }

    /**
     * Step 18.2 — SHA-256 of the look the client approves: tile order, kind, format, cover, and
     * each item's id and framing. Dates, notes and captions don't change the look, so they don't
     * change the hash.
     */
    public function layoutHash(GridPlan $plan): string
    {
        $this->load($plan);

        $canonical = $plan->tiles->sortBy('position')->values()->map(fn (GridTile $tile) => [
            $tile->kind->value,
            $tile->format->value,
            $tile->cover_asset_id,
            $tile->assets->map(function (Asset $asset) {
                [$x, $y, $zoom] = self::pivotFraming($asset);

                return [$asset->id, round($x, 4), round($y, 4), round($zoom, 2)];
            })->values()->all(),
        ])->all();

        return hash('sha256', (string) json_encode($canonical));
    }

    /**
     * Derived production progress — "4 of 9 posts made · 3 scheduled · 2 live". Never stored.
     *
     * @return array{tiles: int, posts: int, approved: int, scheduled: int, live: int, failed: int, stage: string|null}
     */
    public function progress(GridPlan $plan): array
    {
        $this->load($plan);

        $tiles = $plan->tiles->count();
        $posts = $plan->tiles->filter(fn (GridTile $tile) => $tile->post !== null);
        $statusOf = fn (GridTile $tile): PostStatus => $tile->post->status;

        $live = $posts->filter(function (GridTile $tile) use ($plan) {
            return $this->instagramTarget($plan, $tile)?->status === PostTargetStatus::Published;
        })->count();
        $scheduled = $posts->filter(fn (GridTile $tile) => in_array($statusOf($tile), [PostStatus::Scheduled, PostStatus::Publishing], true))->count();
        $approved = $posts->filter(fn (GridTile $tile) => $statusOf($tile) === PostStatus::Approved)->count();
        $failed = $posts->filter(fn (GridTile $tile) => $statusOf($tile) === PostStatus::Failed
            || $this->instagramTarget($plan, $tile)?->status === PostTargetStatus::Failed)->count();

        return [
            'tiles' => $tiles,
            'posts' => $posts->count(),
            'approved' => $approved,
            'scheduled' => $scheduled,
            'live' => $live,
            'failed' => $failed,
            'stage' => match (true) {
                $tiles > 0 && $live === $tiles => 'live',
                $posts->isNotEmpty() => 'in_production',
                default => null,
            },
        ];
    }

    /**
     * Whether a tile holds a video (a Reel's clip).
     */
    public static function hasVideo(GridTile $tile): bool
    {
        return $tile->assets->contains(fn (Asset $asset) => $asset->type === AssetType::Video);
    }

    /**
     * Focus point and zoom from a `grid_tile_assets` pivot (centre, 1× when not loaded through
     * `GridTile::assets()`).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function pivotFraming(Asset $asset): array
    {
        /** @var object{crop_x?: mixed, crop_y?: mixed, crop_zoom?: mixed}|null $pivot */
        $pivot = $asset->getRelationValue('pivot');

        return [
            (float) ($pivot->crop_x ?? 0.5),
            (float) ($pivot->crop_y ?? 0.5),
            (float) ($pivot->crop_zoom ?? 1.0),
        ];
    }

    /**
     * An attached asset's media pivot row (`grid_tile_assets` or `post_assets`), ready to copy to
     * another owner at `$order` — the same framing columns live on both.
     *
     * @return array{sort_order: int, crop_x: float, crop_y: float, crop_zoom: float, alt_text: string|null}
     */
    public static function pivotRow(Asset $asset, int $order): array
    {
        [$x, $y, $zoom] = self::pivotFraming($asset);
        /** @var object{alt_text?: string|null}|null $pivot */
        $pivot = $asset->getRelationValue('pivot');

        return [
            'sort_order' => $order,
            'crop_x' => round($x, 4),
            'crop_y' => round($y, 4),
            'crop_zoom' => round($zoom, 2),
            'alt_text' => $pivot->alt_text ?? null,
        ];
    }

    /**
     * The formats a tile may take on Instagram: the feed framings the editor offers, plus 9:16
     * for Reels.
     *
     * @return list<MediaRatio>
     */
    public static function formatsFor(GridTile $tile): array
    {
        return $tile->kind === GridTileKind::Reel
            ? [MediaRatio::Vertical]
            : array_values(array_filter(
                MediaRatio::optionsFor(Platform::Instagram),
                fn (MediaRatio $ratio) => $ratio !== MediaRatio::Vertical,
            ));
    }
}
