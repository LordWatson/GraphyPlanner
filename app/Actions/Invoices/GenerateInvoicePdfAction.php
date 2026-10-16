<?php

namespace App\Actions\Invoices;

use App\Contracts\AssetStorage;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GenerateInvoicePdfAction
{
    public function __construct(private AssetStorage $storage) {}

    /**
     * Render the branded "Studio Paper" PDF for an invoice with a locked total (finalized, or a
     * fixed-amount invoice with no line items) and store it via AssetStorage, populating the same
     * pdf_* columns the manual-upload flow (UploadInvoicePdfAction) already uses.
     *
     * Also called by the send / mark-paid / void actions so the stored PDF's status stamp always
     * matches the invoice.
     */
    public function __invoke(Invoice $invoice): Invoice
    {
        $invoice->loadMissing(['items', 'client', 'organization']);

        if (! $invoice->canGeneratePdf()) {
            throw ValidationException::withMessages([
                'invoice' => 'Finalize the invoice before generating its PDF.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $pdfContent = $this->render($invoice);

            $filename = $invoice->generatedPdfFilename();
            $tmpPath = tempnam(sys_get_temp_dir(), 'invoice-pdf-');

            try {
                file_put_contents($tmpPath, $pdfContent);

                $file = new UploadedFile($tmpPath, $filename, 'application/pdf', null, true);

                if ($invoice->hasPdf()) {
                    $this->storage->delete($invoice->pdf_disk, $invoice->pdf_path);
                }

                $stored = $this->storage->store($file, "invoices/{$invoice->org_id}/{$invoice->client_id}");
            } finally {
                if (file_exists($tmpPath)) {
                    unlink($tmpPath);
                }
            }

            $invoice->update([
                'pdf_disk' => $stored['disk'],
                'pdf_path' => $stored['path'],
                'pdf_url' => $stored['url'],
                'pdf_original_filename' => $filename,
                'pdf_mime_type' => $stored['mime_type'],
                'pdf_size' => $stored['size'],
            ]);

            Log::info('Invoice PDF generated', [
                'invoice_id' => $invoice->id,
                'client_id' => $invoice->client_id,
                'status' => $invoice->status->value,
            ]);

            return $invoice;
        });
    }

    /**
     * Render the invoice view to raw PDF bytes.
     */
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'client', 'organization']);

        // dompdf caches the metrics of the bundled brand fonts here on first use.
        File::ensureDirectoryExists(storage_path('fonts'));

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'client' => $invoice->client,
            'organization' => $invoice->organization,
            'items' => $invoice->items,
            'itemsSubtotal' => $invoice->itemsSubtotal(),
            'fontDir' => resource_path('fonts/pdf'),
        ])->setPaper('a4')->output();
    }
}
