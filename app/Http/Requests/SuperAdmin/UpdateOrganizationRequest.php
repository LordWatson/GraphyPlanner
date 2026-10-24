<?php

namespace App\Http\Requests\SuperAdmin;

use App\Models\Organization;
use App\Support\Options\CurrencyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('slug'))) {
            $this->merge(['slug' => Str::slug($this->input('slug'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:140',
                'alpha_dash',
                Rule::unique('organizations', 'slug')->ignore($organization->id),
                // The preview fixture is found by its slug — it keeps it, and nobody else takes it.
                $organization->isPreviewFixture()
                    ? Rule::in([Organization::PREVIEW_SLUG])
                    : Rule::notIn([Organization::PREVIEW_SLUG]),
            ],
            'default_timezone' => ['required', 'timezone:all'],
            'default_currency' => ['required', 'string', Rule::in(CurrencyOptions::codes())],
            'ai_monthly_image_quota' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_design_partner' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated details, shaped for `UpdateOrganizationAction`.
     *
     * @return array{name: string, slug: string, default_timezone: string, default_currency: string, ai_monthly_image_quota: int|null, is_design_partner: bool}
     */
    public function details(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'slug' => $this->string('slug')->toString(),
            'default_timezone' => $this->string('default_timezone')->toString(),
            'default_currency' => $this->string('default_currency')->toString(),
            'ai_monthly_image_quota' => $this->filled('ai_monthly_image_quota') ? $this->integer('ai_monthly_image_quota') : null,
            'is_design_partner' => $this->boolean('is_design_partner'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'default_timezone' => 'timezone',
            'default_currency' => 'currency',
            'ai_monthly_image_quota' => 'monthly AI image quota',
        ];
    }
}
