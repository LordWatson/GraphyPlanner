<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Support\Options\CurrencyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Step 15.2.2 — validates the public "create your organization" signup. The email must be
 * unique across every `users` row (compared lowercased, matching Fortify's
 * `lowercase_usernames`), so a signup can never collide with an existing account.
 */
class OrganizationRegistrationRequest extends FormRequest
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
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', Rule::in(CurrencyOptions::codes())],
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * The validated signup, shaped for `RegisterOrganizationAction`.
     *
     * @return array{organization_name: string, default_timezone: string, default_currency: string, name: string, email: string, password: string}
     */
    public function registration(): array
    {
        return [
            'organization_name' => $this->string('organization_name')->toString(),
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
            'organization_name' => 'organization name',
            'default_timezone' => 'timezone',
            'default_currency' => 'currency',
        ];
    }
}
