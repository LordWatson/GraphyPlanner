<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\MoneyFormatter;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Emails an invoice to the client's billing contact (Resend), with the stored PDF attached when
 * there is one. Also used for payment reminders (`$isReminder`), which reuse the same layout with
 * reminder copy.
 */
class InvoiceSentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?string $personalMessage = null,
        public bool $isReminder = false,
    ) {
        // Step I-2.8: every mailable runs on the mail queue.
        $this->onQueue(Queues::MAIL);
    }

    public function build(): self
    {
        $invoice = $this->invoice->loadMissing(['client', 'organization']);
        $studio = $invoice->organization->name;

        $subject = $this->isReminder
            ? "Reminder: invoice {$invoice->invoice_number} from {$studio}".($invoice->isOverdue() ? ' is overdue' : '')
            : "Invoice {$invoice->invoice_number} from {$studio}";

        $mail = $this
            ->subject($subject)
            ->view('emails.invoice-sent', [
                'invoice' => $invoice,
                'client' => $invoice->client,
                'organization' => $invoice->organization,
                'amount' => MoneyFormatter::format((float) $invoice->amount, $invoice->currency),
                'personalMessage' => $this->personalMessage,
                'isReminder' => $this->isReminder,
                'portalUrl' => route('portal.invoices.show', $invoice),
            ]);

        if ($invoice->hasPdf()) {
            $mail->attachFromStorageDisk(
                $invoice->pdf_disk,
                $invoice->pdf_path,
                $invoice->pdf_original_filename ?? "invoice-{$invoice->invoice_number}.pdf",
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }
}
