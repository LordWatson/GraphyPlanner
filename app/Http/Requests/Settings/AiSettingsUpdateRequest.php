<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step M-7.1: the org's AI enable toggle and monthly token budget (blank = unlimited). Owner-only,
 * enforced by the controller through OrganizationPolicy::update.
 */
class AiSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ai_enabled' => ['required', 'boolean'],
            'ai_monthly_token_budget' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_monthly_token_budget.integer' => 'Enter a whole number of tokens, e.g. 500000, or leave it blank for no limit.',
            'ai_monthly_token_budget.min' => 'Enter a whole number of tokens, e.g. 500000, or leave it blank for no limit.',
        ];
    }
}
