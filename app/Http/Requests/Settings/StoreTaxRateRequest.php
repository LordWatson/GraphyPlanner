<?php

namespace App\Http\Requests\Settings;

use App\Models\TaxRate;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', TaxRate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
