<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendInvoiceAction
{
    public function __construct(
        private GenerateInvoicePdfAction $generatePdf,
        private InvoiceDeliveryService $delivery,
    ) {}

    /**
     * Issue a draft invoice to the client: transition `draft → sent`, refresh the branded PDF (so
     * its status stamp reads "Sent" and it's attached to the email), then email the billing
     * contact and notify the client's portal users.
     *
     * With no recipient (no override and no billing/approval email on the client), the invoice
     * is still marked as sent — e.g. delivered by hand — and simply not emailed.
     *
     * @return array{invoice: Invoice, recipient: string|null, emailed: bool}
     */
    public function __invoke(Invoice $invoice, ?string $recipient = null, ?string $message = null): array
    {
        $invoice->loadMissing(['items', 'client', 'organization']);

        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft invoices can be sent.',
            ]);
        }

        if ($invoice->needsFinalizing()) {
            throw ValidationException::withMessages([
                'status' => 'Finalize this invoice before sending it, so its total is locked in.',
            ]);
        }

        if ((float) $invoice->amount <= 0) {
            throw ValidationException::withMessages([
                'status' => 'An invoice needs an amount greater than zero before it can be sent.',
            ]);
        }

        $recipient = $this->delivery->resolveRecipient($invoice, $recipient);

        DB::transaction(function () use ($invoice, $recipient) {
            $invoice->update([
                'status' => InvoiceStatus::Sent,
                'sent_at' => now(),
                'sent_to_email' => $recipient,
            ]);

            if ($invoice->shouldRefreshGeneratedPdf()) {
                ($this->generatePdf)($invoice);
            }

            Log::info('Invoice sent', [
                'client_id' => $invoice->client_id,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'emailed' => $recipient !== null,
            ]);
        });

        $emailed = $this->delivery->deliver($invoice, $recipient, $message) && $recipient !== null;

        if ($recipient !== null && ! $emailed) {
            // The email bounced on our side — don't claim it went out.
            $invoice->update(['sent_to_email' => null]);
        }

        return ['invoice' => $invoice, 'recipient' => $recipient, 'emailed' => $emailed];
    }
}
