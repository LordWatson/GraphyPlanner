<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\BrandKind;
use App\Enums\OrganizationType;
use App\Support\Options\CurrencyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step B-2.1 — validates the business/creator signup at `/start`. The same uniqueness and password
 * rules as the agency signup, plus "I'm a…" (`brand_kind`). The brand's name is the workspace name;
 * no agency vocabulary ("studio", "client") appears anywhere.
 */
class StoreBusinessRegistrationRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return true;
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
            'brand_kind' => ['required', new Enum(BrandKind::class)],
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', Rule::in(CurrencyOptions::codes())],
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * The validated signup, shaped for `RegisterOrganizationAction` (a business workspace).
     *
     * @return array{organization_name: string, type: OrganizationType, brand_kind: string, default_timezone: string, default_currency: string, name: string, email: string, password: string}
     */
    public function registration(): array
    {
        return [
            'organization_name' => $this->string('organization_name')->toString(),
            'type' => OrganizationType::Business,
            'brand_kind' => $this->string('brand_kind')->toString(),
            'default_timezone' => $this->string('default_timezone')->toString(),
            'default_currency' => $this->string('default_currency')->toString(),
            'name' => $this->string('name')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_name' => 'brand name',
            'brand_kind' => 'brand type',
            'default_timezone' => 'timezone',
            'default_currency' => 'currency',
        ];
    }
}
