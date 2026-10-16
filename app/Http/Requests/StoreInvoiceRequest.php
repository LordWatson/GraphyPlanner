<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [Invoice::class, $this->route('client')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: leave it empty to build the invoice from line items instead.
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
