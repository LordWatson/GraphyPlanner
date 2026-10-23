<?php

namespace App\Http\Requests\Business;

use App\Enums\BusinessImagePreset;
use App\Enums\ImageAspectRatio;
use App\Policies\AiImageGenerationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step B-6.5 — one "Generate" click from a business Image Studio preset. Authorization (the AI
 * feature flag and {@see AiImageGenerationPolicy::generate} on the brand) lives in
 * the controller, which resolves the brand from the current workspace.
 */
class GenerateImagePresetRequest extends FormRequest
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
            'preset' => ['required', Rule::enum(BusinessImagePreset::class)],
            'text' => ['required', 'string', 'max:120'],
            'aspect_ratio' => ['nullable', Rule::enum(ImageAspectRatio::class)],
            'variant_count' => ['required', 'integer', 'min:1', 'max:'.(int) config('ai.images.max_variants', 4)],
            'request_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'text.required' => 'Add a line of text for the image first.',
            'text.max' => 'Keep it under 120 characters.',
        ];
    }

    public function preset(): BusinessImagePreset
    {
        return BusinessImagePreset::from((string) $this->validated('preset'));
    }

    public function ratio(): ?ImageAspectRatio
    {
        $value = $this->validated('aspect_ratio');

        return is_string($value) ? ImageAspectRatio::from($value) : null;
    }
}
