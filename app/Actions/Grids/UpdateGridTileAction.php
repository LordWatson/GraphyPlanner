<?php

namespace App\Actions\Grids;

use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Models\Asset;
use App\Models\GridTile;
use App\Models\User;
use App\Services\Grids\GridPlanChanges;
use App\Services\Grids\GridPlanService;
use Illuminate\Support\Facades\DB;

class UpdateGridTileAction
{
    /** Fields that don't change the look — they never reopen an approved plan. */
    private const NOTE_FIELDS = ['team_note', 'caption_idea', 'planned_local_date', 'planned_local_time'];

    public function __construct(private readonly GridPlanChanges $changes = new GridPlanChanges) {}

    /**
     * Step 18.4 — one tile's kind, format, media (in carousel order, with framing and alt text),
     * Reel cover, placeholder colour, team note, caption idea and planned date/time (local to the
     * account). Only the fields sent change. Changing the kind without a format picks that kind's
     * default (9:16 for a Reel, 3:4 otherwise).
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(GridTile $tile, User $user, array $data): GridTile
    {
        return DB::transaction(function () use ($tile, $user, $data) {
            $plan = $this->changes->lock($tile->plan);
            $tile->refresh();

            $attributes = array_intersect_key($data, array_flip([
                'kind', 'format', 'cover_asset_id', 'placeholder_colour', ...self::NOTE_FIELDS,
            ]));

            foreach (['team_note', 'caption_idea', 'planned_local_time'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $attributes[$field] = filled($attributes[$field]) ? trim((string) $attributes[$field]) : null;
                }
            }

            if (array_key_exists('planned_local_date', $attributes) && blank($attributes['planned_local_date'])) {
                $attributes['planned_local_date'] = null;
            }

            if (isset($attributes['kind']) && ! isset($attributes['format'])) {
                $kind = GridTileKind::from((string) $attributes['kind']);

                if ($kind !== $tile->kind) {
                    $attributes['format'] = $kind === GridTileKind::Reel || $tile->format === MediaRatio::Vertical
                        ? $kind->defaultFormat()->value
                        : $tile->format->value;
                }
            }

            $tile->fill($attributes);
            $affectsLook = $tile->isDirty(['kind', 'format', 'cover_asset_id', 'placeholder_colour']);
            $tile->save();

            $media = array_key_exists('asset_ids', $data) || array_key_exists('asset_crops', $data) || array_key_exists('asset_alt_texts', $data);

            if ($media) {
                $before = $this->mediaSignature($tile);

                AddGridTilesAction::syncMedia(
                    $tile,
                    array_values(array_map('intval', array_key_exists('asset_ids', $data)
                        ? (array) $data['asset_ids']
                        : $tile->assets()->pluck('assets.id')->all())),
                    is_array($data['asset_crops'] ?? null) ? $data['asset_crops'] : null,
                    is_array($data['asset_alt_texts'] ?? null) ? $data['asset_alt_texts'] : null,
                );

                $affectsLook = $affectsLook || $before !== $this->mediaSignature($tile);
            }

            $this->changes->record($plan, $user, 'tile_updated', $affectsLook);

            return $tile->refresh();
        });
    }

    /**
     * The media order and framing, to tell a real change from an alt-text-only save.
     */
    private function mediaSignature(GridTile $tile): string
    {
        return (string) json_encode($tile->assets()->get()->map(fn (Asset $asset) => [$asset->id, ...GridPlanService::pivotFraming($asset)])->all());
    }
}
