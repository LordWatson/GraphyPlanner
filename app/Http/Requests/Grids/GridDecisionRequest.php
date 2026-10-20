<?php

namespace App\Http\Requests\Grids;

use App\Enums\GridPlanStatus;
use App\Models\GridPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Step 18.8 — the client's decision on a grid: approve, or request changes with optional flagged
 * tiles (`tiles: {tileId: note}`), plus the grid `version` they were looking at.
 *
 * Used by the review link (the token is checked by `ReviewController` first, so `authorize` is
 * open there) and the portal (`PortalGridController` authorizes against `GridPlanPolicy`).
 */
class GridDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $grid = $this->route('grid');

        if (! $grid instanceof GridPlan) {
            return true;
        }

        $to = GridPlanStatus::tryFrom((string) $this->input('decision'));

        return $to === null ? $this->user()?->can('view', $grid) ?? false : $this->user()?->can('transition', [$grid, $to]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in([GridPlanStatus::Approved->value, GridPlanStatus::ChangesRequested->value])],
            'version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'tiles' => ['nullable', 'array', 'max:60'],
            'tiles.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('decision') === GridPlanStatus::ChangesRequested->value
                && blank($this->input('comment')) && blank($this->input('tiles'))) {
                $validator->errors()->add('comment', __('Tell us what to change, or flag the tiles you’d like changed.'));
            }
        }];
    }

    /**
     * Flagged tiles, limited to this grid's own tiles.
     *
     * @return array<int, string|null>
     */
    public function flaggedTiles(GridPlan $plan): array
    {
        $ids = $plan->tiles()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return collect((array) $this->validated('tiles', []))
            ->mapWithKeys(fn ($note, $id) => [(int) $id => is_string($note) ? $note : null])
            ->filter(fn ($note, int $id) => in_array($id, $ids, true))
            ->all();
    }
}
