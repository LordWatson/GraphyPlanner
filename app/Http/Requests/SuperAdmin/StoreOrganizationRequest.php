<?php

namespace App\Http\Requests\SuperAdmin;

use App\Concerns\ProfileValidationRules;
use App\Models\Organization;
use App\Support\Options\CurrencyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Step 15.3 — a super admin onboards an organization on a customer's behalf. No password: the
 * owner chooses one from the link in their welcome email.
 */
class StoreOrganizationRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Organization::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:120'],
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', Rule::in(CurrencyOptions::codes())],
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
        ];
    }

    /**
     * The validated onboarding, shaped for `RegisterOrganizationAction` (no password).
     *
     * @return array{organization_name: string, default_timezone: string, default_currency: string, name: string, email: string}
     */
    public function registration(): array
    {
        return [
            'organization_name' => $this->string('organization_name')->toString(),
            'default_timezone' => $this->string('default_timezone')->toString(),
            'default_currency' => $this->string('default_currency')->toString(),
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_name' => 'organization name',
            'default_timezone' => 'timezone',
            'default_currency' => 'currency',
            'name' => 'owner name',
            'email' => 'owner email',
        ];
    }
}
