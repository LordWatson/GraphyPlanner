<?php

namespace App\Http\Requests\Grids;

use App\Http\Requests\Grids\Concerns\GridRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 18.4 — the full new order (top-left first) and the grid `version` the planner saw.
 */
class ReorderGridTilesRequest extends FormRequest
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
            'tile_ids' => ['required', 'array', 'min:1'],
            'tile_ids.*' => ['integer', 'distinct'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
