<?php

namespace App\Actions\InvoiceItems;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateInvoiceItemAction
{
    /**
     * Update an existing line item on a draft invoice.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(InvoiceItem $invoiceItem, array $data): InvoiceItem
    {
        if ($invoiceItem->invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'invoice' => 'Line items can only be edited while the invoice is a draft.',
            ]);
        }

        return DB::transaction(function () use ($invoiceItem, $data) {
            $invoiceItem->update($data);

            if ($invoiceItem->invoice->is_finalized) {
                $invoiceItem->invoice->refreshFinalizedAmount();
            }

            Log::info('Invoice item updated', [
                'invoice_id' => $invoiceItem->invoice_id,
                'invoice_item_id' => $invoiceItem->id,
            ]);

            return $invoiceItem;
        });
    }
}
