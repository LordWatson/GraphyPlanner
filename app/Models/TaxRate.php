<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\TaxRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-4.1 — a per-organization tax rate (e.g. "UK Standard" at 20.00) chosen on invoice line
 * items and applied to a client's retainer. Archived (never hard-deleted) once invoices have
 * snapshotted it, so historical invoices keep the rate they were billed at.
 *
 * @property int $id
 * @property int $org_id
 * @property string $name
 * @property string $rate
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'name', 'rate', 'sort_order', 'archived_at'])]
class TaxRate extends Model
{
    /** @use HasFactory<TaxRateFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'archived_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Rates still offered for new line items (archived rates stay usable on their existing rows).
     *
     * @param  Builder<TaxRate>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }
}
