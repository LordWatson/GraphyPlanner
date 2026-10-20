<?php

namespace App\Http\Requests\Grids;

use App\Enums\CampaignColour;
use App\Enums\GridTileKind;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Http\Requests\Grids\Concerns\GridRules;
use App\Models\GridTile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Step 18.4 — the tile inspector's save. Media must be the client's; the format must be one
 * Instagram offers (9:16 only for Reels, checked here so a bad save never lands); a carousel stays
 * within the registry's bounds.
 */
class UpdateGridTileRequest extends FormRequest
{
    use GridRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->plan());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clientId = $this->plan()->client_id;

        return [
            'kind' => ['sometimes', new Enum(GridTileKind::class)],
            'format' => ['sometimes', new Enum(MediaRatio::class)],
            'asset_ids' => ['sometimes', 'array', 'max:10'],
            'asset_ids.*' => ['integer', 'distinct', $this->clientAsset($clientId)],
            'asset_crops' => ['sometimes', 'array'],
            'asset_crops.*.x' => ['numeric', 'between:0,1'],
            'asset_crops.*.y' => ['numeric', 'between:0,1'],
            'asset_crops.*.zoom' => ['numeric', 'between:1,3'],
            'asset_alt_texts' => ['sometimes', 'array'],
            'asset_alt_texts.*' => ['nullable', 'string', 'max:1000'],
            'cover_asset_id' => ['sometimes', 'nullable', 'integer', $this->clientAsset($clientId)],
            'placeholder_colour' => ['sometimes', 'nullable', new Enum(CampaignColour::class)],
            'team_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'caption_idea' => ['sometimes', 'nullable', 'string', 'max:'.Platform::Instagram->captionLimit()],
            'planned_local_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'planned_local_time' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var GridTile $tile */
            $tile = $this->route('tile');
            $kind = GridTileKind::tryFrom((string) $this->input('kind', $tile->kind->value)) ?? $tile->kind;
            $format = MediaRatio::tryFrom((string) $this->input('format', ''));

            if ($format !== null && ($kind === GridTileKind::Reel) !== ($format === MediaRatio::Vertical)) {
                $validator->errors()->add('format', $kind === GridTileKind::Reel
                    ? __('A Reel goes out full screen (9:16).')
                    : __('That format is for Reels. Pick a feed format.'));
            }

            $max = Platform::Instagram->spec()->format('carousel')->maxItems;

            if ($this->has('asset_ids') && count((array) $this->input('asset_ids')) > $max) {
                $validator->errors()->add('asset_ids', __('A carousel takes up to :max items.', ['max' => $max]));
            }
        }];
    }
}
