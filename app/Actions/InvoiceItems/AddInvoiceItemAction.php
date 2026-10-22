<?php

namespace App\Actions\InvoiceItems;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Invoices\TaxRateSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AddInvoiceItemAction
{
    /**
     * Add a new line item to a draft invoice, assigning it the next sort order.
     *
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Invoice $invoice, array $data): InvoiceItem
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'invoice' => 'Line items can only be added while the invoice is a draft.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $nextSortOrder = ((int) $invoice->items()->max('sort_order')) + 1;

            // Step M-4.1: snapshot the chosen tax rate so it can't drift later.
            $data = TaxRateSnapshot::apply($data, $invoice->org_id);

            $item = $invoice->items()->create([
                ...$data,
                'org_id' => $invoice->org_id,
                'sort_order' => $nextSortOrder,
            ]);

            if ($invoice->is_finalized) {
                $invoice->refreshFinalizedAmount();
            }

            Log::info('Invoice item added', [
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $item->id,
            ]);

            return $item;
        });
    }
}
