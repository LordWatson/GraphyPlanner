<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step CG-2.6 — a super admin sets an org's billing override: `inherit` (follow the global switch),
 * `on` or `off`.
 */
class SetOrganizationBillingRequest extends FormRequest
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
            'mode' => ['required', 'string', Rule::in(['inherit', 'on', 'off'])],
        ];
    }

    public function enabled(): ?bool
    {
        return match ($this->validated('mode')) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }
}
