<?php

namespace App\Http\Requests;

use App\Models\InvoiceItem;
use Illuminate\Foundation\Http\FormRequest;

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
        return [
            'description' => ['required', 'string'],
            'is_billable' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:1'],
            'post_id' => ['nullable', 'integer', 'exists:posts,id'],
        ];
    }
}
