<?php

namespace App\Services;

use App\Enums\Role;
use App\Mail\InvoiceSentMail;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\InvoiceIssued;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers an invoice to the client: emails the billing contact (PDF attached) and drops an
 * in-app notification for the client's portal users. Shared by `SendInvoiceAction` and
 * `SendInvoiceReminderAction` so both resolve recipients and handle mail failures the same way.
 */
class InvoiceDeliveryService
{
    /**
     * Resolve who an invoice email should go to: an explicit override, else the client's billing
     * contact (falling back to its approval contact). Null when there's nobody to email.
     */
    public function resolveRecipient(Invoice $invoice, ?string $override = null): ?string
    {
        return filled($override) ? $override : $invoice->client->invoiceRecipientEmail();
    }

    /**
     * Email the invoice (when there's a recipient) and notify portal users.
     *
     * Mail failures are logged and reported back as `false` rather than thrown: the invoice's
     * state change has already been committed by the caller, and the user can retry delivery via
     * a reminder.
     */
    public function deliver(Invoice $invoice, ?string $recipient, ?string $message = null, bool $isReminder = false): bool
    {
        $delivered = true;

        if ($recipient !== null) {
            try {
                Mail::to($recipient)->send(new InvoiceSentMail($invoice, $message, $isReminder));

                Log::info($isReminder ? 'Invoice reminder emailed' : 'Invoice emailed', [
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'org_id' => $invoice->org_id,
                ]);
            } catch (Throwable $exception) {
                $delivered = false;

                Log::error('Invoice email failed', [
                    'invoice_id' => $invoice->id,
                    'client_id' => $invoice->client_id,
                    'is_reminder' => $isReminder,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        User::query()
            ->where('client_id', $invoice->client_id)
            ->where('role', Role::ClientReviewer)
            ->get()
            ->each(fn (User $user) => $user->notify(new InvoiceIssued($invoice, $isReminder)));

        return $delivered;
    }
}
