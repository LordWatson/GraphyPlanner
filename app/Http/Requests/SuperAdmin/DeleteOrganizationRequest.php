<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Organization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Hard delete is irreversible, so the super admin must type the organization's name to confirm,
 * and re-enter their password (Step I-16.2 / M-1.2).
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
            // Step I-16.2 / M-1.2: an irreversible purge re-checks who is at the keyboard.
            'password' => ['required', 'string', 'current_password'],
        ];
    }
}
