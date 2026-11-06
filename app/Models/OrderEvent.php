<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.2 — the audit trail on an order: every status change, the download links issued, the
 * refund. Append-only (no `updated_at`) so the history of a money record can't be rewritten.
 *
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property Carbon|null $created_at
 */
#[Fillable(['order_id', 'type', 'data', 'created_at'])]
class OrderEvent extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
