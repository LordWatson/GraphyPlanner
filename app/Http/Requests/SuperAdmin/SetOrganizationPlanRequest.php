<?php

namespace App\Http\Requests\SuperAdmin;

use App\Support\Plans\PlanCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step CG-2.6 — a super admin sets an org's plan. Blank/`null` resets to the type default.
 */
class SetOrganizationPlanRequest extends FormRequest
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
        $keys = array_keys(app(PlanCatalog::class)->all());

        return [
            'plan' => ['nullable', 'string', Rule::in($keys)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function planKey(): ?string
    {
        $plan = $this->validated('plan');

        return $plan === '' ? null : $plan;
    }
}
