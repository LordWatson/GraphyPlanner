<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\Invoice;
use App\Support\Options\CurrencyOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        /** @var Client $client */
        $client = $this->route('client');

        return [
            // Optional: omitted → the organization's default currency. The org default is always
            // accepted, even if it's not in the curated list.
            'currency' => ['nullable', 'string', Rule::in([...CurrencyOptions::codes(), $client->organization->default_currency])],
            // Optional: leave it empty to build the invoice from line items instead.
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            // Step M-4.1: invoice-level tax rate for a fixed-amount invoice (must be this org's).
            'tax_rate_id' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')->where('org_id', $client->org_id)],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
