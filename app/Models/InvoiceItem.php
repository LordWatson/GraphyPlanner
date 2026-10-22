<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\InvoiceItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $invoice_id
 * @property int $org_id
 * @property int|null $post_id
 * @property int|null $campaign_id
 * @property string $description
 * @property bool $is_billable
 * @property string|null $price
 * @property int $quantity
 * @property int|null $tax_rate_id
 * @property string|null $tax_rate
 * @property string|null $tax_name
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'invoice_id', 'org_id', 'post_id', 'campaign_id', 'description', 'is_billable', 'price', 'quantity',
    'tax_rate_id', 'tax_rate', 'tax_name', 'sort_order',
])]
class InvoiceItem extends Model
{
    /** @use HasFactory<InvoiceItemFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_billable' => 'boolean',
            'price' => 'decimal:2',
            'quantity' => 'integer',
            'tax_rate' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * The net total for this line item: zero for non-billable items, regardless of price.
     */
    public function lineTotal(): float
    {
        if (! $this->is_billable) {
            return 0.0;
        }

        return (float) $this->price * $this->quantity;
    }

    /**
     * Step M-4.1 — the tax charged on this line (net × rate), or zero when no rate is set or the
     * line isn't billable.
     */
    public function taxAmount(): float
    {
        if ($this->tax_rate === null) {
            return 0.0;
        }

        return round($this->lineTotal() * (float) $this->tax_rate / 100, 2);
    }
}
