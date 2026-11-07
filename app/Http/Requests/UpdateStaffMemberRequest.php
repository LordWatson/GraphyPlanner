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
     * Staff roles a member can be changed into. Shares the single `Role::assignableFor()` allow-list
     * with the invite flow, so neither the client-portal `ClientReviewer` role nor the platform
     * `SuperAdmin` role can ever be granted by editing a member (an Owner must not be able to
     * promote anyone — including themselves — to platform staff).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staffRoles = Role::assignableFor($this->user()->organization->type);

        return [
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only($staffRoles)],
            // The clients this member is put on, when the org restricts access that way. `exists`
            // is scoped to the org's own clients, so a submitted id from another organization is a
            // validation error rather than an assignment.
            'client_ids' => ['sometimes', 'array'],
            'client_ids.*' => [
                'integer',
                Rule::exists('clients', 'id')->where('org_id', $this->user()->org_id),
            ],
        ];
    }
}
