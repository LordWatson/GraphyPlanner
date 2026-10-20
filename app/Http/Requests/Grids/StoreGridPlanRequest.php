<?php

namespace App\Http\Requests\Grids;

use App\Http\Requests\Grids\Concerns\GridRules;
use App\Models\Client;
use App\Models\GridPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 18.4 — a new grid plan. The account must be one of this client's Instagram accounts
 * ("One Instagram account per plan").
 */
class StoreGridPlanRequest extends FormRequest
{
    use GridRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', [GridPlan::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:120'],
            'social_account_id' => ['required', 'integer', $this->instagramAccount($client->id)],
            'campaign_id' => ['nullable', 'integer', Rule::exists('campaigns', 'id')->where('client_id', $client->id)],
            'pinned_count' => ['nullable', 'integer', 'min:0', 'max:'.GridPlan::MAX_PINNED],
            'include_live_feed' => ['nullable', 'boolean'],
            'review_message' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'social_account_id.exists' => __('Pick one of this client’s Instagram accounts.'),
        ];
    }
}
