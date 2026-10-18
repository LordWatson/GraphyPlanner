<?php

namespace App\Http\Requests;

use App\Models\AiImageVariant;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Steps 16.8/16.9: refine / regenerate / make final / discard on one studio tile. `request_id`
 * is generated per click in the browser so a double click resolves to the same child variant.
 */
class ImageVariantActionRequest extends FormRequest
{
    /**
     * Step I-1.9: authorize before validating (see `GenerateImagesRequest`).
     */
    public function authorize(): bool
    {
        $variant = $this->route('variant');

        return $variant instanceof AiImageVariant
            && $variant->generation !== null
            && $this->user()->can('manage', $variant->generation);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['request_id' => ['required', 'uuid']];

        if ($this->routeIs('ai-image-variants.refine')) {
            $rules['instruction'] = ['required', 'string', 'min:2', 'max:300'];
        }

        if ($this->routeIs('ai-image-variants.discard')) {
            $rules = ['discarded' => ['required', 'boolean']];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instruction.required' => 'Tell us what to change, e.g. "warmer light".',
            'instruction.max' => 'Keep it short: under 300 characters.',
        ];
    }
}
