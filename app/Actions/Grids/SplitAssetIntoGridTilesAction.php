<?php

namespace App\Actions\Grids;

use App\Contracts\AssetStorage;
use App\Enums\AssetSource;
use App\Enums\AssetType;
use App\Enums\GridTileKind;
use App\Jobs\ProbeAssetJob;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use Throwable;

class SplitAssetIntoGridTilesAction
{
    /** Rows × columns per split layout. */
    public const LAYOUTS = ['1x3' => [1, 3], '2x3' => [2, 3], '3x3' => [3, 3]];

    /** Each slice is a 3:4 grid tile. */
    private const TILE_RATIO = 3 / 4;

    public function __construct(
        private readonly AssetStorage $storage,
        private readonly AddGridTilesAction $addTiles,
    ) {}

    /**
     * Step 18.4 — slice one image into 3, 6 or 9 grid tiles (1×3, 2×3, 3×3, each 3:4), so a banner
     * runs across whole rows. The source is centre-cropped to the split's overall shape and cut
     * into slices of at least `grids.split_tile_min_width` pixels wide (Instagram's 1080). Each
     * slice becomes a new `Asset` pointing back at its source (`source_asset_id`) — the original is
     * never changed — and the tiles share one `split_group_id`, so they move as one block.
     *
     * @return Collection<int, GridTile> the new tiles, top-left first
     */
    public function __invoke(GridPlan $plan, User $user, Asset $source, string $layout, ?int $atPosition = null): Collection
    {
        [$rows, $columns] = self::LAYOUTS[$layout] ?? throw ValidationException::withMessages(['layout' => __('Pick a 1×3, 2×3 or 3×3 split.')]);

        $slices = $this->slice($source, $rows, $columns);
        $stored = [];

        try {
            foreach ($slices as $bytes) {
                $stored[] = $this->storage->storeContents($bytes, 'image/jpeg', "assets/{$source->org_id}/{$source->client_id}/splits", 'jpg');
            }

            $group = (string) Str::uuid();
            $tileWidth = max(1, (int) config('grids.split_tile_min_width', 1080));

            [$assets, $tiles] = DB::transaction(function () use ($plan, $user, $source, $stored, $group, $tileWidth, $atPosition) {
                $assets = [];

                foreach ($stored as $index => $file) {
                    $asset = Asset::create([
                        'org_id' => $source->org_id,
                        'client_id' => $source->client_id,
                        'campaign_id' => $plan->campaign_id ?? $source->campaign_id,
                        'uploaded_by' => $user->id,
                        'source' => AssetSource::Upload,
                        'type' => AssetType::Image,
                        'disk' => $file['disk'],
                        'path' => $file['path'],
                        'url' => $file['url'],
                        'original_filename' => sprintf('%s — split %d of %d.jpg', pathinfo((string) ($source->original_filename ?? 'image'), PATHINFO_FILENAME), $index + 1, count($stored)),
                        'mime_type' => 'image/jpeg',
                        'size' => $file['size'],
                        'width' => $tileWidth,
                        'height' => (int) round($tileWidth / self::TILE_RATIO),
                        'source_asset_id' => $source->id,
                        'is_ai_generated' => $source->is_ai_generated,
                    ]);

                    ProbeAssetJob::dispatch($asset->id)->afterCommit();
                    $assets[] = $asset;
                }

                $tiles = ($this->addTiles)($plan, $user, [
                    'at_position' => $atPosition ?? 1,
                    'tiles' => array_map(fn (Asset $asset) => [
                        'kind' => GridTileKind::Image->value,
                        'asset_ids' => [$asset->id],
                        'split_group_id' => $group,
                    ], $assets),
                ]);

                return [$assets, $tiles];
            });
        } catch (Throwable $e) {
            foreach ($stored as $file) {
                $this->storage->delete($file['disk'], $file['path']);
            }

            throw $e;
        }

        Log::info('Image split into grid tiles', [
            'grid_plan_id' => $plan->id,
            'org_id' => $plan->org_id,
            'user_id' => $user->id,
            'source_asset_id' => $source->id,
            'layout' => $layout,
            'tile_count' => count($assets),
        ]);

        return $tiles;
    }

    /**
     * @return list<string> JPEG bytes per slice, row by row, left to right
     */
    private function slice(Asset $source, int $rows, int $columns): array
    {
        if ($source->type !== AssetType::Image || blank($source->disk) || blank($source->path)) {
            throw ValidationException::withMessages(['asset_id' => __('Only an uploaded image can be split. Upload it to the library first.')]);
        }

        $bytes = $this->storage->contents((string) $source->disk, (string) $source->path);

        if ($bytes === null) {
            throw ValidationException::withMessages(['asset_id' => __('We couldn’t read that image. Try uploading it again.')]);
        }

        try {
            $image = ImageManager::gd()->read($bytes);
        } catch (Throwable) {
            throw ValidationException::withMessages(['asset_id' => __('We couldn’t read that image. Try uploading it again.')]);
        }

        $minWidth = max(1, (int) config('grids.split_tile_min_width', 1080));
        $splitRatio = ($columns * self::TILE_RATIO) / $rows;

        // The largest centred box of the split's shape.
        $width = $image->width();
        $height = (int) round($width / $splitRatio);

        if ($height > $image->height()) {
            $height = $image->height();
            $width = (int) round($height * $splitRatio);
        }

        $sliceWidth = intdiv($width, $columns);

        if ($sliceWidth < $minWidth) {
            $needWidth = $minWidth * $columns;
            $needHeight = (int) round($needWidth / $splitRatio);

            throw ValidationException::withMessages(['asset_id' => __('This image is too small for a :layout split. It needs to be at least :w × :h pixels so each tile is sharp.', [
                'layout' => "{$rows}×{$columns}",
                'w' => number_format($needWidth),
                'h' => number_format($needHeight),
            ])]);
        }

        $image->crop($width, $height, intdiv($image->width() - $width, 2), intdiv($image->height() - $height, 2));
        $sliceHeight = intdiv($height, $rows);
        $slices = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                $piece = (clone $image)->crop($sliceWidth, $sliceHeight, $column * $sliceWidth, $row * $sliceHeight);
                $piece->resize($minWidth, (int) round($minWidth / self::TILE_RATIO));
                $slices[] = (string) $piece->toJpeg(92);
            }
        }

        return $slices;
    }
}
