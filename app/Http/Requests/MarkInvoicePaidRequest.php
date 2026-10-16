<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkInvoicePaidRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('markPaid', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
