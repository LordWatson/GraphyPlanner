<?php

namespace App\Actions\InvoiceItems;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteInvoiceItemAction
{
    /**
     * Delete a line item from a draft invoice.
     */
    public function __invoke(InvoiceItem $invoiceItem): void
    {
        if ($invoiceItem->invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'invoice' => 'Line items can only be deleted while the invoice is a draft.',
            ]);
        }

        DB::transaction(function () use ($invoiceItem) {
            $invoice = $invoiceItem->invoice;

            $invoiceItem->delete();

            if ($invoice->is_finalized) {
                $invoice->refreshFinalizedAmount();
            }

            Log::info('Invoice item deleted', [
                'invoice_id' => $invoiceItem->invoice_id,
                'invoice_item_id' => $invoiceItem->id,
            ]);
        });
    }
}
