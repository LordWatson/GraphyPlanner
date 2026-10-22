<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Support\MoneyFormatter;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Step M-4.2 — tells the agency's billing users (Owner/Strategist) that a client has reported
 * paying an invoice from the portal, so someone confirms (or reverts) it. Dispatched from
 * `ReportInvoicePaymentAction`.
 */
class InvoicePaymentReported extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => Queues::NOTIFICATIONS];
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
            'title' => 'Payment reported',
            'body' => "A client reported paying invoice {$this->invoice->invoice_number} ({$amount}) — confirm it",
            'url' => route('invoices.show', $this->invoice),
            'invoice_id' => $this->invoice->id,
        ];
    }
}
