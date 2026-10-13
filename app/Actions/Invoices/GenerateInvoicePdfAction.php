<?php

namespace App\Actions\Invoices;

use App\Contracts\AssetStorage;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GenerateInvoicePdfAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Render a branded PDF for a finalized invoice and store it via AssetStorage, populating the
     * same pdf_* columns the manual-upload flow (UploadInvoicePdfAction) already uses.
     */
    public function __invoke(Invoice $invoice): Invoice
    {
        if (! $invoice->is_finalized) {
            throw ValidationException::withMessages([
                'invoice' => 'Only a finalized invoice can have a PDF generated for it.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->loadMissing(['items', 'client', 'organization']);

            $pdfContent = Pdf::loadView('pdf.invoice', [
                'invoice' => $invoice,
                'client' => $invoice->client,
                'organization' => $invoice->organization,
                'items' => $invoice->items,
                'itemsSubtotal' => $invoice->items->sum(fn ($item) => $item->lineTotal()),
            ])->output();

            $filename = "invoice-{$invoice->invoice_number}.pdf";
            $tmpPath = tempnam(sys_get_temp_dir(), 'invoice-pdf-');
            file_put_contents($tmpPath, $pdfContent);

            $file = new UploadedFile($tmpPath, $filename, 'application/pdf', null, true);

            if ($invoice->hasPdf()) {
                $this->storage->delete($invoice->pdf_disk, $invoice->pdf_path);
            }

            $directory = "invoices/{$invoice->org_id}/{$invoice->client_id}";
            $stored = $this->storage->store($file, $directory);

            $invoice->update([
                'pdf_disk' => $stored['disk'],
                'pdf_path' => $stored['path'],
                'pdf_url' => $stored['url'],
                'pdf_original_filename' => $filename,
                'pdf_mime_type' => $stored['mime_type'],
                'pdf_size' => $stored['size'],
            ]);

            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }

            Log::info('Invoice PDF generated', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
            ]);

            return $invoice;
        });
    }
}
