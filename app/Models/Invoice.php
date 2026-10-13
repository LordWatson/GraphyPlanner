<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $currency
 * @property Carbon $issue_date
 * @property Carbon|null $due_date
 * @property string|null $description
 * @property Carbon|null $sent_at
 * @property Carbon|null $paid_at
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
    'currency', 'issue_date', 'due_date', 'description', 'sent_at', 'paid_at',
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
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
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
        $itemsSubtotal = $this->items()->get()->sum(fn (InvoiceItem $item) => $item->lineTotal());
        $retainerAmount = (float) ($this->retainer_amount_snapshot ?? 0);

        $this->update(['amount' => $itemsSubtotal + $retainerAmount]);
    }
}
