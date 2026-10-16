<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MarkInvoicePaidAction
{
    public function __construct(private GenerateInvoicePdfAction $generatePdf) {}

    /**
     * Mark a sent invoice as paid, recording when the payment arrived (defaults to now) and an
     * optional payment reference, then refresh the generated PDF so it doubles as a receipt.
     *
     * @param  array{paid_at?: string|null, payment_reference?: string|null}  $data
     */
    public function __invoke(Invoice $invoice, array $data = []): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Sent) {
            throw ValidationException::withMessages([
                'status' => 'Only sent invoices can be marked as paid.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $paidAt = filled($data['paid_at'] ?? null)
                ? Carbon::parse($data['paid_at'])->setTimeFrom(now())
                : now();

            $invoice->update([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $paidAt,
                'payment_reference' => $data['payment_reference'] ?? null,
            ]);

            if ($invoice->shouldRefreshGeneratedPdf()) {
                ($this->generatePdf)($invoice);
            }

            Log::info('Invoice marked as paid', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice;
        });
    }
}
