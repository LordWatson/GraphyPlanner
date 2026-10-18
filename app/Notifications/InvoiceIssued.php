<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\MoneyFormatter;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Notifies client-portal users (`Role::ClientReviewer`) of the invoice's client that a new invoice
 * has been issued (or that a payment reminder was sent) — dispatched from `SendInvoiceAction` /
 * `SendInvoiceReminderAction` alongside the `InvoiceSentMail` email.
 */
class InvoiceIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Invoice $invoice,
        private readonly bool $isReminder = false,
    ) {}

    /**
     * Step I-2.8: bell notifications and mail each run on their own queue.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $amount = MoneyFormatter::format((float) $this->invoice->amount, $this->invoice->currency);

        return [
            'title' => $this->isReminder ? 'Payment reminder' : 'New invoice',
            'body' => $this->isReminder
                ? "Invoice {$this->invoice->invoice_number} ({$amount}) is awaiting payment"
                : "Invoice {$this->invoice->invoice_number} for {$amount} is ready",
            'url' => route('portal.invoices.show', $this->invoice),
            'invoice_id' => $this->invoice->id,
        ];
    }
}
