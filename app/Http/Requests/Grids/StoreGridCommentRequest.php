<?php

namespace App\Http\Requests\Grids;

use App\Http\Requests\Grids\Concerns\GridRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 18.7 — a grid comment, optionally pinned to one of the grid's own tiles (mirrors
 * `StorePostCommentRequest`). `internal_only` is ignored for client reviewers by the action.
 */
class StoreGridCommentRequest extends FormRequest
{
    use GridRules;

    public function authorize(): bool
    {
        return $this->user()->can('comment', $this->plan());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
            'internal_only' => ['nullable', 'boolean'],
            'grid_tile_id' => ['nullable', 'integer', Rule::exists('grid_tiles', 'id')->where('grid_plan_id', $this->plan()->id)],
        ];
    }
}
