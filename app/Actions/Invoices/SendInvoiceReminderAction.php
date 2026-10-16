<?php

namespace App\Actions\Invoices;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoiceDeliveryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendInvoiceReminderAction
{
    /**
     * Minimum gap between two reminders for the same invoice, so a client can't be spammed by
     * repeated clicks.
     */
    public const COOLDOWN_HOURS = 24;

    public function __construct(private InvoiceDeliveryService $delivery) {}

    /**
     * Re-send a sent, unpaid invoice to the client as a payment reminder (PDF attached), and
     * notify the client's portal users.
     *
     * @return array{invoice: Invoice, recipient: string}
     */
    public function __invoke(Invoice $invoice, ?string $recipient = null, ?string $message = null): array
    {
        $invoice->loadMissing(['client', 'organization']);

        if ($invoice->status !== InvoiceStatus::Sent) {
            throw ValidationException::withMessages([
                'invoice' => 'Reminders can only be sent for invoices awaiting payment.',
            ]);
        }

        if ($invoice->last_reminded_at?->gt(now()->subHours(self::COOLDOWN_HOURS))) {
            throw ValidationException::withMessages([
                'invoice' => 'A reminder was already sent '.$invoice->last_reminded_at->diffForHumans().' — try again tomorrow.',
            ]);
        }

        $recipient = $this->delivery->resolveRecipient($invoice, $recipient ?? $invoice->sent_to_email);

        if ($recipient === null) {
            throw ValidationException::withMessages([
                'email' => 'Add a billing email to the client (or enter one here) to send a reminder.',
            ]);
        }

        if (! $this->delivery->deliver($invoice, $recipient, $message, isReminder: true)) {
            throw ValidationException::withMessages([
                'invoice' => 'The reminder email could not be sent. Please try again shortly.',
            ]);
        }

        $invoice->update([
            'last_reminded_at' => now(),
            'reminder_count' => $invoice->reminder_count + 1,
        ]);

        Log::info('Invoice reminder sent', [
            'invoice_id' => $invoice->id,
            'client_id' => $invoice->client_id,
            'reminder_count' => $invoice->reminder_count,
        ]);

        return ['invoice' => $invoice, 'recipient' => $recipient];
    }
}
