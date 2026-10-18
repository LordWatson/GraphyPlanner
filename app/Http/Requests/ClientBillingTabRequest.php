<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Services\InvoiceLedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The client page's Billing tab query string: invoice number/description search and the
 * ledger's status filters (including the derived `overdue` / `outstanding`).
 */
class ClientBillingTabRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Client $client */
        $client = $this->route('client');

        return $this->user()->can('viewBilling', $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(InvoiceLedgerService::STATUS_FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{search: string|null, status: string|null}
     */
    public function filters(): array
    {
        return [
            'search' => $this->string('search')->trim()->toString() ?: null,
            'status' => $this->string('status')->toString() ?: null,
        ];
    }
}
