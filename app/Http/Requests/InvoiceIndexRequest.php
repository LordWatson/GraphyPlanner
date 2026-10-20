<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Services\InvoiceLedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InvoiceIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewLedger', Invoice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(InvoiceLedgerService::STATUS_FILTERS)],
            'client_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'string', Rule::in(InvoiceLedgerService::SORTS)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * The validated ledger filters, with empty values dropped.
     *
     * @return array{status?: string|null, client_id?: int|string|null, search?: string|null, from?: string|null, to?: string|null, sort?: string|null, direction?: string|null}
     */
    public function filters(): array
    {
        /** @var array{status?: string|null, client_id?: int|string|null, search?: string|null, from?: string|null, to?: string|null, sort?: string|null, direction?: string|null} $filters */
        $filters = array_filter($this->validated(), fn ($value) => filled($value));

        return $filters;
    }
}
