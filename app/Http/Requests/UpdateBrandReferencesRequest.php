<?php

namespace App\Http\Requests;

use App\Enums\ReferenceImageRole;
use App\Models\Client;
use App\Policies\BrandBrainPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step 16.4: save a client's Brand Brain visual references (ordered, role-tagged).
 */
class UpdateBrandReferencesRequest extends FormRequest
{
    public function authorize(BrandBrainPolicy $policy): bool
    {
        /** @var Client $client */
        $client = $this->route('client');

        return $this->user() !== null && $policy->update($this->user(), $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'references' => ['present', 'array', 'max:'.(int) config('ai.images.max_references', 8)],
            'references.*.asset_id' => ['required', 'integer', 'distinct'],
            'references.*.role' => ['required', Rule::in(array_map(fn (ReferenceImageRole $role) => $role->value, ReferenceImageRole::brandRoles()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'references.max' => 'Pick up to :max references.',
            'references.*.asset_id.distinct' => 'Each image can only be added once.',
        ];
    }
}
