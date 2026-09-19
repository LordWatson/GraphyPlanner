<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffMemberRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('staff'));
    }

    /**
     * Staff roles a member can be changed into — `Role::ClientReviewer` is reserved for the
     * client-portal invitation flow and is never assignable here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staffRoles = array_filter(Role::cases(), fn (Role $role) => $role !== Role::ClientReviewer);

        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only($staffRoles)],
        ];
    }
}
