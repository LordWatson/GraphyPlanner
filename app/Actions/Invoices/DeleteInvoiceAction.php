<?php

namespace App\Actions\Invoices;

use App\Contracts\AssetStorage;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteInvoiceAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Delete an invoice, removing its stored PDF (if any) first.
     */
    public function __invoke(Invoice $invoice): void
    {
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
