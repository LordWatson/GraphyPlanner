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
            'finalized_at' => $invoice->finalized_at?->toIso8601String(),
            'sent_to_email' => $invoice->sent_to_email,
            'last_reminded_at' => $invoice->last_reminded_at?->toIso8601String(),
            'reminder_count' => $invoice->reminder_count,
            'payment_reference' => $invoice->payment_reference,
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'pdf_url' => $invoice->pdf_url,
            'pdf_original_filename' => $invoice->pdf_original_filename,
            'pdf_is_generated' => $invoice->hasPdf() && $invoice->pdf_original_filename === $invoice->generatedPdfFilename(),
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'is_billable' => $item->is_billable,
                'price' => $item->price !== null ? (string) $item->price : null,
                'quantity' => $item->quantity,
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
        $canUpdate = $user->can('update', $invoice);

        return [
            'update' => $canUpdate,
            'delete' => in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true) && $user->can('delete', $invoice),
            'send' => $isDraft && $user->can('send', $invoice),
            'mark_paid' => $isSent && $user->can('markPaid', $invoice),
            'finalize' => $isDraft && ! $invoice->is_finalized && $user->can('finalize', $invoice),
            'manage_items' => $isDraft && $canUpdate,
            'edit_currency' => $invoice->canChangeCurrency() && $canUpdate,
            'edit_amount' => $isDraft && ! $invoice->is_finalized && $invoice->items->isEmpty() && $canUpdate,
            'generate_pdf' => $invoice->canGeneratePdf() && $canUpdate,
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
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'description' => $invoice->description,
            'is_unpaid' => $invoice->status === InvoiceStatus::Sent,
            'is_overdue' => $invoice->isOverdue(),
            'days_overdue' => $invoice->daysOverdue(),
            'pdf_url' => $invoice->pdf_url,
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
