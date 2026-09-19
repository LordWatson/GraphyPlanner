<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\StaffInvitation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStaffInvitationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [StaffInvitation::class, $this->user()->organization]);
    }

    /**
     * Staff roles a user can be invited into — `Role::ClientReviewer` is reserved for the
     * client-portal invitation flow (Step 6.1/6.2) and is never assignable here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $staffRoles = array_filter(Role::cases(), fn (Role $role) => $role !== Role::ClientReviewer);

        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only($staffRoles)],
        ];
    }

    /**
     * Reject a duplicate pending invitation to the same email within the organization.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $organization = $this->user()->organization;

            if (! $organization || blank($this->input('email'))) {
                return;
            }

            $hasPending = $organization->staffInvitations()
                ->where('email', $this->input('email'))
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($hasPending) {
                $validator->errors()->add('email', 'There is already a pending invitation for this email.');
            }
        });
    }
}
