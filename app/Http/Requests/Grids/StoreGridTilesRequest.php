<?php

namespace App\Http\Requests\Grids;

use App\Enums\CampaignColour;
use App\Enums\GridTileKind;
use App\Enums\Platform;
use App\Http\Requests\Grids\Concerns\GridRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Step 18.4 — add tiles: each from library assets, Image Studio variants (selected ones only) or
 * a placeholder. Every asset and variant must belong to the plan's client; a carousel stays
 * within the registry's Instagram carousel bounds.
 */
class StoreGridTilesRequest extends FormRequest
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
            'at_position' => ['nullable', 'integer', 'min:1'],
            'tiles' => ['required', 'array', 'min:1', 'max:30'],
            'tiles.*.kind' => ['nullable', new Enum(GridTileKind::class)],
            'tiles.*.asset_ids' => ['nullable', 'array', 'max:10'],
            'tiles.*.asset_ids.*' => ['integer', 'distinct', $this->clientAsset($clientId)],
            'tiles.*.variant_ids' => ['nullable', 'array', 'max:10'],
            'tiles.*.variant_ids.*' => ['integer', 'distinct', $this->selectedVariant($clientId)],
            'tiles.*.placeholder_colour' => ['nullable', new Enum(CampaignColour::class)],
            'tiles.*.team_note' => ['nullable', 'string', 'max:2000'],
            'tiles.*.caption_idea' => ['nullable', 'string', 'max:'.Platform::Instagram->captionLimit()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tiles.*.asset_ids.*.exists' => __('That image isn’t in this client’s library.'),
            'tiles.*.variant_ids.*.exists' => __('Pick a finished Image Studio image for this client first.'),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $max = Platform::Instagram->spec()->format('carousel')->maxItems;

            foreach ((array) $this->input('tiles', []) as $index => $tile) {
                $count = count((array) ($tile['asset_ids'] ?? [])) + count((array) ($tile['variant_ids'] ?? []));

                if (($tile['kind'] ?? null) === GridTileKind::Carousel->value && $count > $max) {
                    $validator->errors()->add("tiles.{$index}", __('A carousel takes up to :max items.', ['max' => $max]));
                }
            }
        }];
    }
}
