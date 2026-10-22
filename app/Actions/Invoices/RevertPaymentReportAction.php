<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Step M-4.2 — "Not received yet": the org rejects a payment the client reported, moving the
 * invoice `pending_confirmation → sent` and clearing what the client reported, so they can try
 * again. The opposite handshake end to `MarkInvoicePaidAction` confirming the payment.
 */
class RevertPaymentReportAction
{
    public function __invoke(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::PendingConfirmation) {
            throw ValidationException::withMessages([
                'status' => 'Only an invoice with a reported payment can be sent back for another attempt.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Sent,
                'payment_reported_at' => null,
                'payment_reported_method' => null,
                'payment_reported_reference' => null,
                'payment_reported_note' => null,
            ]);

            Log::info('Invoice payment report reverted', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
            ]);

            return $invoice;
        });
    }
}
