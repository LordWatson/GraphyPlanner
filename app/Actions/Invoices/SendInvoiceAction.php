<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendInvoiceAction
{
    /**
     * Mark the invoice as sent to the client for billing.
     */
    public function __invoke(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft invoices can be sent.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->update([
                'status' => InvoiceStatus::Sent,
                'sent_at' => now(),
            ]);

            Log::info('Invoice sent', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice;
        });
    }
}
