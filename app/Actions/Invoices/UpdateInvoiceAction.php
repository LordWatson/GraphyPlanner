<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceAction
{
    /**
     * Update an invoice's editable details (amount, currency, dates, description). Status,
     * invoice_number, org_id and client_id are never touched here. Once finalized, `amount` is
     * locked; `currency` can only change while the invoice is an unfinalized draft (after that
     * the total — and what the client has been sent — is fixed in that currency).
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Invoice $invoice, array $data): Invoice
    {
        if (($data['amount'] ?? null) === null) {
            unset($data['amount']);
        }

        if ($invoice->is_finalized && array_key_exists('amount', $data) && (string) $data['amount'] !== (string) $invoice->amount) {
            throw ValidationException::withMessages([
                'amount' => 'The amount can no longer be changed once the invoice is finalized.',
            ]);
        }

        if (($data['currency'] ?? null) === null) {
            unset($data['currency']);
        }

        if (array_key_exists('currency', $data) && $data['currency'] !== $invoice->currency && ! $invoice->canChangeCurrency()) {
            throw ValidationException::withMessages([
                'currency' => 'The currency can only be changed while the invoice is an unfinalized draft.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $previousCurrency = $invoice->currency;

            $invoice->update($data);

            Log::info('Invoice updated', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                ...($invoice->currency !== $previousCurrency
                    ? ['currency_from' => $previousCurrency, 'currency_to' => $invoice->currency]
                    : []),
            ]);

            return $invoice;
        });
    }
}
