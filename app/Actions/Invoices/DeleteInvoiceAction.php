<?php

namespace App\Actions\Invoices;

use App\Contracts\AssetStorage;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class DeleteInvoiceAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Delete an invoice, removing its stored PDF (if any) first.
     */
    public function __invoke(Invoice $invoice): void
    {
        // Issued invoices are part of the books: an unpaid one is voided instead (keeping its
        // number and history), and a paid one is never removed.
        if (in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Paid], true)) {
            throw ValidationException::withMessages([
                'status' => $invoice->status === InvoiceStatus::Sent
                    ? 'This invoice has been sent — void it instead of deleting it.'
                    : 'Paid invoices cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($invoice) {
            if ($invoice->hasPdf()) {
                $this->storage->delete($invoice->pdf_disk, $invoice->pdf_path);
            }

            $invoice->delete();

            Log::info('Invoice deleted', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
            ]);
        });
    }
}
