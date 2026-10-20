<?php

namespace App\Http\Requests\Grids;

use App\Actions\Grids\SplitAssetIntoGridTilesAction;
use App\Http\Requests\Grids\Concerns\GridRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 18.4 — slice one of the client's images into 3, 6 or 9 tiles.
 */
class SplitGridAssetRequest extends FormRequest
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
        return [
            'asset_id' => ['required', 'integer', $this->clientAsset($this->plan()->client_id)],
            'layout' => ['required', Rule::in(array_keys(SplitAssetIntoGridTilesAction::LAYOUTS))],
            'at_position' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
