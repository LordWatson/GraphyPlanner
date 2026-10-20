<?php

namespace App\Actions\Grids;

use App\Enums\AssetType;
use App\Enums\GridTileKind;
use App\Models\AiImageVariant;
use App\Models\Asset;
use App\Models\GridPlan;
use App\Models\GridTile;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AddGridTilesAction
{
    public function __construct(private readonly GridPlanChanges $changes = new GridPlanChanges) {}

    /**
     * Step 18.4 — add tiles at a position (1 = top-left, the default) and shift the rest along.
     * Each entry becomes one tile: library assets, Image Studio variants (their selected image) or
     * a placeholder. A tile's kind defaults from its media — one image is an image, several a
     * carousel, a video a Reel, nothing a placeholder. `StoreGridTilesRequest` has already checked
     * every asset and variant belongs to the plan's client.
     *
     * @param  array<string, mixed>  $data  `tiles` (one entry per new tile) and optionally `at_position`
     * @return Collection<int, GridTile> the new tiles, in display order
     */
    public function __invoke(GridPlan $plan, User $user, array $data): Collection
    {
        [$updated, $created] = DB::transaction(function () use ($plan, $user, $data) {
            $locked = $this->changes->lock($plan);
            $existing = GridPlanChanges::orderedIds($locked);
            $at = max(1, min(count($existing) + 1, (int) ($data['at_position'] ?? 1)));

            $created = collect();

            foreach (array_values((array) $data['tiles']) as $offset => $input) {
                $input = (array) $input;
                $assetIds = $this->assetIds($input);
                $kind = $this->kind($input, $assetIds);

                /** @var GridTile $tile */
                $tile = $locked->tiles()->create([
                    // Out of the way until the positions are rewritten below.
                    'position' => 20000 + $offset,
                    'kind' => $kind,
                    'format' => $kind->defaultFormat(),
                    'placeholder_colour' => $input['placeholder_colour'] ?? null,
                    'team_note' => self::text($input['team_note'] ?? null),
                    'caption_idea' => self::text($input['caption_idea'] ?? null),
                    'split_group_id' => $input['split_group_id'] ?? null,
                ]);

                self::syncMedia($tile, $assetIds);
                $created->push($tile);
            }

            GridPlanChanges::writePositions($locked, array_values([
                ...array_slice($existing, 0, $at - 1),
                ...$created->map(fn (GridTile $tile) => $tile->id)->all(),
                ...array_slice($existing, $at - 1),
            ]));

            return [$this->changes->record($locked, $user, 'tiles_added'), $created->map(fn (GridTile $tile) => $tile->refresh())];
        });

        $plan->setRawAttributes($updated->getAttributes(), true);

        return $created;
    }

    /**
     * Attach media to a tile in this order, keeping the framing and alt text of items it already
     * had and centring new ones.
     *
     * @param  list<int>  $assetIds
     * @param  array<int|string, mixed>|null  $crops  `{assetId: {x, y, zoom}}`
     * @param  array<int|string, mixed>|null  $altTexts  `{assetId: text}`
     */
    public static function syncMedia(GridTile $tile, array $assetIds, ?array $crops = null, ?array $altTexts = null): void
    {
        $current = $tile->assets()->get()->keyBy('id');
        $attach = [];

        foreach (array_values(array_unique($assetIds)) as $order => $assetId) {
            /** @var Asset|null $existing */
            $existing = $current->get($assetId);
            /** @var object{crop_x?: mixed, crop_y?: mixed, crop_zoom?: mixed, alt_text?: mixed}|null $pivot */
            $pivot = $existing?->getRelationValue('pivot');
            $crop = is_array($crops[$assetId] ?? null) ? $crops[$assetId] : null;

            $attach[$assetId] = [
                'sort_order' => $order,
                'crop_x' => round(min(1, max(0, (float) ($crop['x'] ?? $pivot->crop_x ?? 0.5))), 4),
                'crop_y' => round(min(1, max(0, (float) ($crop['y'] ?? $pivot->crop_y ?? 0.5))), 4),
                'crop_zoom' => round(min(3, max(1, (float) ($crop['zoom'] ?? $pivot->crop_zoom ?? 1))), 2),
                'alt_text' => $altTexts !== null && array_key_exists($assetId, $altTexts)
                    ? self::text($altTexts[$assetId])
                    : self::text($pivot->alt_text ?? null),
            ];
        }

        $tile->assets()->sync($attach);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function assetIds(array $input): array
    {
        $ids = array_map('intval', (array) ($input['asset_ids'] ?? []));
        $variantIds = array_map('intval', (array) ($input['variant_ids'] ?? []));

        if ($variantIds !== []) {
            $byVariant = AiImageVariant::query()->withoutGlobalScopes()->whereKey($variantIds)->pluck('asset_id', 'id');

            foreach ($variantIds as $variantId) {
                if ($byVariant->get($variantId) !== null) {
                    $ids[] = (int) $byVariant->get($variantId);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<int>  $assetIds
     */
    private function kind(array $input, array $assetIds): GridTileKind
    {
        if (filled($input['kind'] ?? null)) {
            return GridTileKind::from((string) $input['kind']);
        }

        if ($assetIds === []) {
            return GridTileKind::Placeholder;
        }

        $hasVideo = Asset::query()->withoutGlobalScopes()->whereKey($assetIds)->where('type', AssetType::Video)->exists();

        return match (true) {
            $hasVideo && count($assetIds) === 1 => GridTileKind::Reel,
            count($assetIds) > 1 => GridTileKind::Carousel,
            default => GridTileKind::Image,
        };
    }

    private static function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
