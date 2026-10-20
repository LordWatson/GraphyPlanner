<?php

namespace App\Http\Requests\Grids;

use App\Actions\Grids\PlanGridScheduleAction;
use App\Http\Requests\Grids\Concerns\GridRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 18.10 — a start date, a cadence and a time for the grid's sequence (preview and apply).
 */
class GridScheduleRequest extends FormRequest
{
    use GridRules;

    public function authorize(): bool
    {
        return $this->user()->can('produce', $this->plan());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'cadence' => ['required', Rule::in(PlanGridScheduleAction::CADENCES)],
            'weekdays' => ['nullable', 'array', 'required_if:cadence,'.PlanGridScheduleAction::WEEKDAYS],
            'weekdays.*' => ['integer', 'between:1,7', 'distinct'],
            'every_n_days' => ['nullable', 'integer', 'between:1,30', 'required_if:cadence,'.PlanGridScheduleAction::EVERY_N_DAYS],
        ];
    }

    /**
     * @return array{start_date: string, time: string, cadence: string, weekdays?: list<int>|null, every_n_days?: int|null}
     */
    public function schedule(): array
    {
        /** @var array{start_date: string, time: string, cadence: string, weekdays?: list<int>|null, every_n_days?: int|null} $validated */
        $validated = $this->validated();

        return $validated;
    }
}
