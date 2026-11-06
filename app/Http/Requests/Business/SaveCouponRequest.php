<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Step CG-4.4 — a discount code. Exactly one of percent-off or amount-off; a code with neither (or
 * both) is a code nobody can reason about.
 */
class SaveCouponRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'product_id' => ['nullable', 'integer'],
            'percent_off' => ['nullable', 'integer', 'min:1', 'max:100'],
            'amount_off_minor' => ['nullable', 'integer', 'min:1', 'max:5000000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $percent = $this->input('percent_off');
            $amount = $this->input('amount_off_minor');

            if (($percent === null) === ($amount === null)) {
                $validator->errors()->add('percent_off', __('Set either a percentage or an amount off — not both.'));
            }
        });
    }
}
