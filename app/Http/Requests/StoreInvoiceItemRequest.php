<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', [InvoiceItem::class, $this->route('invoice')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Step I-1.9: referenced records must belong to this client — a bare `exists:` rule would
        // accept another organization's ids.
        $invoice = $this->route('invoice');
        $clientId = $invoice instanceof Invoice ? $invoice->client_id : null;

        return [
            'description' => ['required', 'string'],
            'is_billable' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'post_id' => ['nullable', 'integer', Rule::exists('posts', 'id')->where('client_id', $clientId)],
        ];
    }
}
