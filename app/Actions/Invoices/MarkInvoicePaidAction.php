<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MarkInvoicePaidAction
{
    /**
     * Mark the invoice as paid.
     */
    public function __invoke(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => 'Only sent invoices can be marked as paid.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => now(),
            ]);

            Log::info('Invoice marked as paid', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice;
        });
    }
}
