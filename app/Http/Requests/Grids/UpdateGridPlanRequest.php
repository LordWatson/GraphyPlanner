<?php

namespace App\Http\Requests\Grids;

use App\Http\Requests\Grids\Concerns\GridRules;
use App\Models\GridPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 18.4 — a grid plan's settings. The Instagram account is fixed once a plan exists.
 */
class UpdateGridPlanRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'campaign_id' => ['sometimes', 'nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $this->plan()->client_id)],
            'pinned_count' => ['sometimes', 'integer', 'min:0', 'max:'.GridPlan::MAX_PINNED],
            'include_live_feed' => ['sometimes', 'boolean'],
            'review_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
