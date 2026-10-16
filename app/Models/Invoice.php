<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $invoice_number
 * @property InvoiceStatus $status
 * @property string $amount
 * @property bool $is_finalized
 * @property string|null $retainer_amount_snapshot
 * @property Carbon|null $finalized_at
 * @property string $currency
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property string|null $description
 * @property Carbon|null $sent_at
 * @property string|null $sent_to_email
 * @property Carbon|null $last_reminded_at
 * @property int $reminder_count
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string|null $pdf_disk
 * @property string|null $pdf_path
 * @property string|null $pdf_url
 * @property string|null $pdf_original_filename
 * @property string|null $pdf_mime_type
 * @property int|null $pdf_size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'invoice_number', 'status', 'amount', 'is_finalized', 'retainer_amount_snapshot',
    'finalized_at', 'currency', 'issue_date', 'due_date', 'description', 'sent_at', 'sent_to_email',
    'last_reminded_at', 'reminder_count', 'paid_at', 'payment_reference', 'voided_at', 'void_reason',
    'pdf_disk', 'pdf_path', 'pdf_url', 'pdf_original_filename', 'pdf_mime_type', 'pdf_size',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'is_finalized' => 'boolean',
            'retainer_amount_snapshot' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'finalized_at' => 'datetime',
            'sent_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'reminder_count' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * Sent, not yet paid, and past its due date. Overdue is derived (never stored) so it can't
     * drift from the dates it's computed from.
     */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Sent
            && $this->due_date !== null
            && $this->due_date->lt(Carbon::today());
    }

    /**
     * Whole days past the due date (0 when not overdue).
     */
    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) $this->due_date?->diffInDays(Carbon::today()) : 0;
    }

    /**
     * The status shown to people: the stored status, except a sent invoice past its due date
     * reads as `overdue`.
     */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'overdue' : $this->status->value;
    }

    public function displayStatusLabel(): string
    {
        return $this->isOverdue() ? 'Overdue' : $this->status->label();
    }

    /**
     * Sum of every line item's lineTotal() (billable items only contribute).
     */
    public function itemsSubtotal(): float
    {
        return (float) $this->items->sum(fn (InvoiceItem $item) => $item->lineTotal());
    }

    /**
     * Whether this invoice is built from line items that haven't been locked in yet — sending it
     * in that state would bill a stale `amount`, so it must be finalized first.
     */
    public function needsFinalizing(): bool
    {
        return ! $this->is_finalized && $this->items->isNotEmpty();
    }

    /**
     * The billing currency can only change while nothing about the total is locked in: a draft
     * that hasn't been finalized (finalizing fixes the total, sending shares it with the client).
     */
    public function canChangeCurrency(): bool
    {
        return $this->status === InvoiceStatus::Draft && ! $this->is_finalized;
    }

    /**
     * A branded PDF can be generated once there's a locked total to print: either the invoice is
     * finalized, or it's a simple fixed-amount invoice with no line items at all.
     */
    public function canGeneratePdf(): bool
    {
        return $this->is_finalized || ($this->items->isEmpty() && (float) $this->amount > 0);
    }

    /**
     * The filename every generated PDF is stored under (a manual upload keeps its own name).
     */
    public function generatedPdfFilename(): string
    {
        return "invoice-{$this->invoice_number}.pdf";
    }

    /**
     * Whether a lifecycle change (send / paid / void) should re-render the PDF so its status
     * stamp stays accurate — only when there's no PDF yet or the current one was generated by
     * us, never over a manually uploaded override.
     */
    public function shouldRefreshGeneratedPdf(): bool
    {
        return $this->canGeneratePdf()
            && (! $this->hasPdf() || $this->pdf_original_filename === $this->generatedPdfFilename());
    }

    /**
     * Chronological lifecycle events derived from the invoice's timestamps.
     *
     * @return array<int, array{event: string, label: string, at: string, detail: string|null}>
     */
    public function timeline(): array
    {
        $events = [
            ['event' => 'created', 'label' => 'Created', 'at' => $this->created_at, 'detail' => null],
            ['event' => 'finalized', 'label' => 'Finalized', 'at' => $this->finalized_at, 'detail' => null],
            ['event' => 'sent', 'label' => 'Sent', 'at' => $this->sent_at, 'detail' => $this->sent_to_email ? "Emailed to {$this->sent_to_email}" : 'Marked as sent'],
            ['event' => 'reminded', 'label' => 'Reminder sent', 'at' => $this->last_reminded_at, 'detail' => $this->reminder_count > 1 ? "{$this->reminder_count} reminders so far" : null],
            ['event' => 'paid', 'label' => 'Paid', 'at' => $this->paid_at, 'detail' => $this->payment_reference ? "Ref. {$this->payment_reference}" : null],
            ['event' => 'voided', 'label' => 'Voided', 'at' => $this->voided_at, 'detail' => $this->void_reason],
        ];

        return collect($events)
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortBy(fn (array $event) => $event['at'])
            ->map(fn (array $event) => [...$event, 'at' => $event['at']->toIso8601String()])
            ->values()
            ->all();
    }

    /**
     * Sent invoices whose due date has passed.
     *
     * @param  Builder<Invoice>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', InvoiceStatus::Sent)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::today());
    }

    /**
     * Whether a PDF has been uploaded for this invoice.
     */
    public function hasPdf(): bool
    {
        return $this->pdf_disk !== null && $this->pdf_path !== null;
    }

    /**
     * Recompute and persist Invoice::amount from the current line items plus the already
     * snapshotted retainer, for an already-finalized invoice whose items were edited after the
     * fact (so the locked-in total — and a subsequently regenerated PDF — stay accurate without
     * requiring a separate "un-finalize/re-finalize" step).
     */
    public function refreshFinalizedAmount(): void
    {
        $this->unsetRelation('items');
        $retainerAmount = (float) ($this->retainer_amount_snapshot ?? 0);

        $this->update(['amount' => $this->itemsSubtotal() + $retainerAmount]);
    }
}
