<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 16.11: the org's monthly AI image quota (blank = unlimited). Owner-only, enforced by the
 * controller through OrganizationPolicy::update.
 */
class AiUsageUpdateRequest extends FormRequest
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
            'ai_monthly_image_quota' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ai_monthly_image_quota.integer' => 'Enter a whole number, e.g. 200, or leave it blank for no limit.',
            'ai_monthly_image_quota.min' => 'Enter a whole number, e.g. 200, or leave it blank for no limit.',
        ];
    }
}
