<?php

namespace App\Support\Invoices;

use App\Actions\Invoices\SendInvoiceReminderAction;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;

/**
 * Shapes `Invoice` models into the arrays the Inertia pages consume, so the ledger, the detail
 * page, the client's billing tab and the portal all describe an invoice the same way (derived
 * overdue status included).
 */
final class InvoicePresenter
{
    /**
     * A compact ledger row.
     *
     * @return array<string, mixed>
     */
    public static function row(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'display_status' => $invoice->displayStatus(),
            'display_status_label' => $invoice->displayStatusLabel(),
            'is_overdue' => $invoice->isOverdue(),
            'days_overdue' => $invoice->daysOverdue(),
            'amount' => (string) $invoice->amount,
            'subtotal' => number_format($invoice->netSubtotal(), 2, '.', ''),
            'tax_total' => (string) $invoice->tax_total,
            'tax_breakdown' => $invoice->taxBreakdownRows(),
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'description' => $invoice->description,
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'client' => $invoice->relationLoaded('client') ? [
                'id' => $invoice->client->id,
                'name' => $invoice->client->name,
            ] : null,
        ];
    }

    /**
     * Everything the internal detail/edit pages need.
     *
     * @return array<string, mixed>
     */
    public static function detail(Invoice $invoice): array
    {
        $invoice->loadMissing(['items', 'client']);

        return [
            ...self::row($invoice),
            'is_finalized' => $invoice->is_finalized,
            'needs_finalizing' => $invoice->needsFinalizing(),
            'retainer_amount_snapshot' => $invoice->retainer_amount_snapshot !== null ? (string) $invoice->retainer_amount_snapshot : null,
            'retainer_tax_rate' => $invoice->retainer_tax_rate !== null ? (string) $invoice->retainer_tax_rate : null,
            'retainer_tax_name' => $invoice->retainer_tax_name,
            'tax_rate_id' => $invoice->tax_rate_id,
            'finalized_at' => $invoice->finalized_at?->toIso8601String(),
            'sent_to_email' => $invoice->sent_to_email,
            'last_reminded_at' => $invoice->last_reminded_at?->toIso8601String(),
            'reminder_count' => $invoice->reminder_count,
            'payment_reference' => $invoice->payment_reference,
            // Step M-4.2: what the client reported when they said they'd paid.
            'payment_reported_at' => $invoice->payment_reported_at?->toIso8601String(),
            'payment_reported_method' => $invoice->payment_reported_method,
            'payment_reported_reference' => $invoice->payment_reported_reference,
            'payment_reported_note' => $invoice->payment_reported_note,
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            // Step I-1.6: an authorized route, never a direct storage URL.
            'pdf_url' => $invoice->hasPdf() ? route('invoices.pdf.show', $invoice) : null,
            'pdf_original_filename' => $invoice->pdf_original_filename,
            'pdf_is_generated' => $invoice->hasPdf() && $invoice->pdf_original_filename === $invoice->generatedPdfFilename(),
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'is_billable' => $item->is_billable,
                'price' => $item->price !== null ? (string) $item->price : null,
                'quantity' => $item->quantity,
                'tax_rate_id' => $item->tax_rate_id,
                'tax_rate' => $item->tax_rate !== null ? (string) $item->tax_rate : null,
                'tax_name' => $item->tax_name,
                'tax_amount' => $item->taxAmount(),
                'sort_order' => $item->sort_order,
                'line_total' => $item->lineTotal(),
            ])->values(),
            'items_subtotal' => $invoice->itemsSubtotal(),
            'timeline' => $invoice->timeline(),
            'recipient_email' => $invoice->sent_to_email ?? $invoice->client->invoiceRecipientEmail(),
        ];
    }

    /**
     * The acting user's available actions, combining policy checks with the invoice's state so
     * a button only renders when clicking it can succeed.
     *
     * @return array<string, bool>
     */
    public static function abilities(Invoice $invoice, User $user): array
    {
        $invoice->loadMissing('items');

        $isDraft = $invoice->status === InvoiceStatus::Draft;
        $isSent = $invoice->status === InvoiceStatus::Sent;
        $isPending = $invoice->status === InvoiceStatus::PendingConfirmation;
        $canUpdate = $user->can('update', $invoice);

        return [
            'update' => $canUpdate,
            'delete' => in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true) && $user->can('delete', $invoice),
            'send' => $isDraft && $user->can('send', $invoice),
            // Step M-4.2: the org can mark a sent invoice paid directly (it took the payment itself).
            'mark_paid' => $isSent && $user->can('markPaid', $invoice),
            // Confirm / revert a payment the client reported from the portal.
            'confirm_payment' => $isPending && $user->can('confirmPayment', $invoice),
            'revert_payment' => $isPending && $user->can('confirmPayment', $invoice),
            'finalize' => $isDraft && ! $invoice->is_finalized && $user->can('finalize', $invoice),
            'manage_items' => $isDraft && $canUpdate,
            'edit_currency' => $invoice->canChangeCurrency() && $canUpdate,
            'edit_amount' => $isDraft && ! $invoice->is_finalized && $invoice->items->isEmpty() && $canUpdate,
            'generate_pdf' => $invoice->canGeneratePdf() && $canUpdate,
            // A reported-but-unconfirmed invoice is reverted to `sent` before it can be voided.
            'void' => $isSent && $user->can('void', $invoice),
            'remind' => $isSent
                && ! $invoice->last_reminded_at?->gt(now()->subHours(SendInvoiceReminderAction::COOLDOWN_HOURS))
                && $user->can('remind', $invoice),
            'duplicate' => $user->can('duplicate', $invoice),
        ];
    }

    /**
     * The client portal's read-only view: never exposes internal-only fields (reminder counts,
     * void reasons, recipient emails).
     *
     * @return array<string, mixed>
     */
    public static function portal(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'display_status' => $invoice->displayStatus(),
            'display_status_label' => $invoice->displayStatusLabel(),
            'amount' => (string) $invoice->amount,
            'subtotal' => number_format($invoice->netSubtotal(), 2, '.', ''),
            'tax_total' => (string) $invoice->tax_total,
            'tax_breakdown' => $invoice->taxBreakdownRows(),
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'description' => $invoice->description,
            'is_unpaid' => $invoice->status === InvoiceStatus::Sent,
            'is_overdue' => $invoice->isOverdue(),
            'days_overdue' => $invoice->daysOverdue(),
            // Step M-4.2: the client can report payment on a sent invoice; once reported it shows
            // "awaiting confirmation" and what they told us.
            'can_report_payment' => $invoice->status === InvoiceStatus::Sent,
            'is_pending_confirmation' => $invoice->isPendingConfirmation(),
            'payment_reported_at' => $invoice->payment_reported_at?->toIso8601String(),
            'payment_reported_method' => $invoice->payment_reported_method,
            'payment_reported_reference' => $invoice->payment_reported_reference,
            'payment_reported_note' => $invoice->payment_reported_note,
            // Step I-1.6: served through the portal's own authorized route.
            'pdf_url' => $invoice->hasPdf() ? route('portal.invoices.pdf', $invoice) : null,
            'pdf_original_filename' => $invoice->pdf_original_filename,
            'items' => $invoice->items
                ->map(fn (InvoiceItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'is_billable' => $item->is_billable,
                    'price' => $item->price !== null ? (string) $item->price : null,
                    'quantity' => $item->quantity,
                    'line_total' => $item->lineTotal(),
                ])
                ->values(),
            'items_subtotal' => $invoice->itemsSubtotal(),
            'retainer_amount_snapshot' => $invoice->is_finalized && $invoice->retainer_amount_snapshot !== null
                ? (string) $invoice->retainer_amount_snapshot
                : null,
        ];
    }
}
