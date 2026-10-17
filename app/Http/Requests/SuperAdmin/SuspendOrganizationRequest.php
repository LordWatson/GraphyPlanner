<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;

class SuspendOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $this->user()?->can('suspend', $organization) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Shown to the org's locked-out members on the "suspended" page.
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
