<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Organization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Hard delete is irreversible, so the super admin must type the organization's name to confirm.
 */
class DeleteOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $this->user()?->can('delete', $organization) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'confirm_name' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($organization) {
                    if (mb_strtolower(trim((string) $value)) !== mb_strtolower(trim($organization->name))) {
                        $fail(__('Type the organization’s name exactly to confirm.'));
                    }
                },
            ],
        ];
    }
}
