<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Services\Store\BookingSlotService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.5 — the slot a booking order holds, stored in UTC like every other instant in the app
 * (`posts.scheduled_at_utc`). The brand's timezone only decides which slots are *offered*
 * ({@see BookingSlotService}), never how one is stored.
 *
 * `bookings.unique(product_id, starts_at)` is the double-booking guard; a cancellation frees the
 * slot by deleting the row rather than keeping a cancelled one in the way.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['order_id', 'product_id', 'starts_at', 'ends_at', 'status'])]
class Booking extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScope(OrganizationScope::class);
    }
}
