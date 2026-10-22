<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\TaxRate;
use App\Support\Invoices\InvoiceTotals;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceAction
{
    /**
     * Update an invoice's editable details (amount, currency, tax rate, dates, description).
     * Status, invoice_number, org_id and client_id are never touched here. Once finalized,
     * `amount` is locked; `currency` can only change while the invoice is an unfinalized draft
     * (after that the total — and what the client has been sent — is fixed in that currency).
     *
     * For a fixed-amount invoice (no line items) the submitted `amount` is the net; the grand
     * total, tax and breakdown are recomputed here (Step M-4.1).
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

        $data = $this->recomputeFixedAmountTotals($invoice, $data);

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

    /**
     * For a fixed-amount invoice (no line items), turn a submitted net `amount` and/or
     * `tax_rate_id` into the stored subtotal / tax / breakdown / grand-total columns. Itemised and
     * finalized invoices are left untouched (their totals come from items + the retainer).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function recomputeFixedAmountTotals(Invoice $invoice, array $data): array
    {
        $touchesTotals = array_key_exists('amount', $data) || array_key_exists('tax_rate_id', $data);

        if (! $touchesTotals || $invoice->is_finalized || $invoice->items()->exists()) {
            return $data;
        }

        $net = array_key_exists('amount', $data) ? (float) $data['amount'] : $invoice->netSubtotal();
        $taxRateId = array_key_exists('tax_rate_id', $data) ? $data['tax_rate_id'] : $invoice->tax_rate_id;
        $taxRate = $taxRateId !== null
            ? TaxRate::query()->withoutOrgScope()->where('org_id', $invoice->org_id)->find((int) $taxRateId)
            : null;

        $totals = InvoiceTotals::fromLines([['net' => $net, 'rate' => $taxRate?->rate, 'name' => $taxRate?->name]]);

        return [...$data, ...$totals->toInvoiceColumns(), 'tax_rate_id' => $taxRate?->id];
    }
}
