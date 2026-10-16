<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DuplicateInvoiceAction
{
    public function __construct(private CreateInvoiceAction $createInvoice) {}

    /**
     * Start a new draft from an existing invoice (any status) — the common "same as last month"
     * retainer flow. Copies the notes and every line item; the copy is issued today with the same
     * payment window and currency as the original, gets the next invoice number, and is never finalized (so a
     * changed retainer is picked up when it's finalized). A fixed-amount invoice without items
     * keeps its amount.
     */
    public function __invoke(Invoice $source): Invoice
    {
        $source->loadMissing(['items', 'client.organization']);

        return DB::transaction(function () use ($source) {
            $issueDate = Carbon::today();
            $termsDays = $source->due_date !== null
                ? (int) $source->issue_date->diffInDays($source->due_date)
                : null;

            $copy = ($this->createInvoice)($source->client, [
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $termsDays !== null ? $issueDate->copy()->addDays($termsDays)->toDateString() : null,
                'description' => $source->description,
                'currency' => $source->currency,
                'amount' => $source->items->isEmpty() ? $source->amount : 0,
            ]);

            $source->items->each(fn (InvoiceItem $item) => $copy->items()->create([
                'org_id' => $copy->org_id,
                'post_id' => $item->post_id,
                'description' => $item->description,
                'is_billable' => $item->is_billable,
                'price' => $item->price,
                'quantity' => $item->quantity,
                'sort_order' => $item->sort_order,
            ]));

            Log::info('Invoice duplicated', [
                'source_invoice_id' => $source->id,
                'invoice_id' => $copy->id,
                'client_id' => $copy->client_id,
            ]);

            return $copy;
        });
    }
}
