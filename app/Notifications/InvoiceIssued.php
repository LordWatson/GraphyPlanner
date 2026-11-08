<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Models\User;
use App\Support\MoneyFormatter;
use App\Support\Queues;
use App\Support\WhatsApp\MessageTemplate;
use App\Support\WhatsApp\WhatsAppRouting;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
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
        return ['database' => Queues::NOTIFICATIONS, 'mail' => Queues::MAIL, 'whatsapp' => Queues::WHATSAPP];
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = $notifiable instanceof User ? ['database'] : [];

        return WhatsAppRouting::with($channels, $notifiable, static::class);
    }

    /**
     * Step W-2.5 — the amount and the due date, and nothing else: an invoice message that says
     * more than that is a billing record in somebody's chat history.
     */
    public function toWhatsApp(object $notifiable): WhatsAppTemplateMessage
    {
        return MessageTemplate::InvoiceIssued->fill([
            'client_name' => $notifiable->name ?? 'there',
            'amount' => MoneyFormatter::format((float) $this->invoice->amount, $this->invoice->currency),
            'due_date' => $this->invoice->due_date?->toFormattedDateString() ?? 'on receipt',
            'url' => route('portal.invoices.show', $this->invoice),
        ]);
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
