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

        $retainerAmount = (float) ($invoice->client->retainer_amount ?? 0);

        // A pure retainer invoice (no extra items) is fine as long as there's a retainer to bill.
        if ($invoice->items()->doesntExist() && $retainerAmount <= 0) {
            throw ValidationException::withMessages([
                'invoice' => 'Add at least one line item before finalizing.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $retainerAmount) {
            $invoice->update([
                'amount' => $invoice->itemsSubtotal() + $retainerAmount,
                'retainer_amount_snapshot' => $retainerAmount,
                'is_finalized' => true,
                'finalized_at' => now(),
            ]);

            Log::info('Invoice finalized', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'amount' => (string) $invoice->amount,
            ]);

            return $invoice;
        });
    }
}
