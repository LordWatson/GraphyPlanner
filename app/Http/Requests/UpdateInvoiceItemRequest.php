<?php

namespace App\Http\Requests;

use App\Models\InvoiceItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('invoiceItem'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Step I-1.9: referenced records must belong to this client — a bare `exists:` rule would
        // accept another organization's ids.
        $item = $this->route('invoiceItem');
        $clientId = $item instanceof InvoiceItem ? $item->invoice?->client_id : null;
        $orgId = $item instanceof InvoiceItem ? $item->org_id : null;

        return [
            'description' => ['required', 'string'],
            'is_billable' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'post_id' => ['nullable', 'integer', Rule::exists('posts', 'id')->where('client_id', $clientId)],
            // Step M-4.1: a tax rate must belong to the invoice's organization (Step I-1.9).
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('org_id', $orgId)],
        ];
    }
}
