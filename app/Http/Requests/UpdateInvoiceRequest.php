<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('invoice'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Ignored by UpdateInvoiceAction once the invoice is finalized/built from items.
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
