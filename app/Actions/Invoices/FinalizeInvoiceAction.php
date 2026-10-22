<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FinalizeInvoiceAction
{
    /**
     * Finalize a draft invoice: sum every item's lineTotal(), add the client's current
     * retainer_amount (0 if null), lock the result into Invoice::amount, and snapshot the
     * retainer used so a later change to the client's retainer never alters this invoice again.
     */
    public function __invoke(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'invoice' => 'Only a draft invoice can be finalized.',
            ]);
        }

        if ($invoice->is_finalized) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has already been finalized.',
            ]);
        }

        $invoice->loadMissing('client.defaultTaxRate');
        $retainerAmount = (float) ($invoice->client->retainer_amount ?? 0);

        // A pure retainer invoice (no extra items) is fine as long as there's a retainer to bill.
        if ($invoice->items()->doesntExist() && $retainerAmount <= 0) {
            throw ValidationException::withMessages([
                'invoice' => 'Add at least one line item before finalizing.',
            ]);
        }

        // Step M-4.1: the retainer has no currency of its own — it's read in the client's billing
        // currency. We never convert, so a retainer invoice raised in any other currency is
        // rejected rather than silently mis-added.
        if ($retainerAmount > 0 && $invoice->currency !== $invoice->client->billingCurrency()) {
            throw ValidationException::withMessages([
                'currency' => "This client's retainer is billed in {$invoice->client->billingCurrency()}, so a retainer invoice can't be raised in {$invoice->currency}.",
            ]);
        }

        $retainerRate = $invoice->client->defaultTaxRate;

        return DB::transaction(function () use ($invoice, $retainerAmount, $retainerRate) {
            // Snapshot the retainer and its tax rate, then compute every money column from the
            // items' and retainer's snapshotted rates (InvoiceTotals, via refreshFinalizedAmount).
            $invoice->update([
                'retainer_amount_snapshot' => $retainerAmount,
                'retainer_tax_rate' => $retainerAmount > 0 ? $retainerRate?->rate : null,
                'retainer_tax_name' => $retainerAmount > 0 ? $retainerRate?->name : null,
                'is_finalized' => true,
                'finalized_at' => now(),
            ]);

            $invoice->refreshFinalizedAmount();

            Log::info('Invoice finalized', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'amount' => (string) $invoice->amount,
            ]);

            return $invoice;
        });
    }
}
