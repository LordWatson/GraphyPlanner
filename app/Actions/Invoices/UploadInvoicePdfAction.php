<?php

namespace App\Actions\Invoices;

use App\Contracts\AssetStorage;
use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UploadInvoicePdfAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Store (or replace) the PDF for the given invoice.
     */
    public function __invoke(Invoice $invoice, UploadedFile $file): Invoice
    {
        return DB::transaction(function () use ($invoice, $file) {
            if ($invoice->hasPdf()) {
                $this->storage->delete($invoice->pdf_disk, $invoice->pdf_path);
            }

            $directory = "invoices/{$invoice->org_id}/{$invoice->client_id}";
            $stored = $this->storage->store($file, $directory);

            $invoice->update([
                'pdf_disk' => $stored['disk'],
                'pdf_path' => $stored['path'],
                'pdf_url' => $stored['url'],
                'pdf_original_filename' => $file->getClientOriginalName(),
                'pdf_mime_type' => $stored['mime_type'],
                'pdf_size' => $stored['size'],
            ]);

            Log::info('Invoice PDF uploaded', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
            ]);

            return $invoice;
        });
    }
}
