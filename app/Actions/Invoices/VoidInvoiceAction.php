<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VoidInvoiceAction
{
    public function __construct(private GenerateInvoicePdfAction $generatePdf) {}

    /**
     * Cancel a sent invoice that should no longer be paid (issued in error, replaced, written
     * off). Unlike deleting, voiding keeps the record and its number in the sequence for the
     * audit trail; voided invoices drop out of every outstanding/revenue total. Drafts should
     * simply be deleted, and a paid invoice can't be voided.
     */
    public function __invoke(Invoice $invoice, ?string $reason = null): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => 'Only sent, unpaid invoices can be voided — delete a draft instead.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->update([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            if ($invoice->shouldRefreshGeneratedPdf()) {
                ($this->generatePdf)($invoice);
            }

            Log::info('Invoice voided', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice;
        });
    }
}
