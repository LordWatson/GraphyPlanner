<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step CG-2.6 — a super admin grants temporary AI credits.
 */
class GrantOrganizationCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'credits' => ['required', 'integer', 'min:0', 'max:1000000'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function expiresInDays(): ?int
    {
        $days = $this->validated('expires_in_days');

        return $days === null ? null : (int) $days;
    }
}
