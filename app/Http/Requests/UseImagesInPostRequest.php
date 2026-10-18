<?php

namespace App\Http\Requests;

use App\Models\AiImageGeneration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 16.10: "Use in post" from the studio's selection bar.
 */
class UseImagesInPostRequest extends FormRequest
{
    /**
     * Step I-1.9: authorize before validating (see `GenerateImagesRequest`).
     */
    public function authorize(): bool
    {
        $generation = $this->route('generation');

        return $generation instanceof AiImageGeneration && $this->user()->can('manage', $generation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variant_ids' => ['required', 'array', 'min:1', 'max:10'],
            'variant_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['variant_ids.required' => 'Pick at least one image.'];
    }
}
