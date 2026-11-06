<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.2 — one purchase from the creator store. The buyer is an email address, not a user:
 * nobody signs up to buy. The {@see OrderStatus} map is the authority on what can happen next, and
 * `external_session_id` is unique so a replayed `checkout.session.completed` is a no-op.
 *
 * @property int $id
 * @property int $org_id
 * @property int $product_id
 * @property int|null $coupon_id
 * @property string $buyer_email
 * @property string|null $buyer_name
 * @property int $amount_minor
 * @property string $currency
 * @property OrderStatus $status
 * @property string|null $external_session_id
 * @property string|null $external_payment_id
 * @property string|null $message
 * @property string|null $answer
 * @property Carbon|null $authorised_until
 * @property Carbon|null $paid_at
 * @property Carbon|null $fulfilled_at
 * @property Carbon|null $refunded_at
 * @property int $download_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, OrderEvent> $events
 */
#[Fillable([
    'org_id', 'product_id', 'coupon_id', 'buyer_email', 'buyer_name', 'amount_minor', 'currency',
    'status', 'external_session_id', 'external_payment_id', 'message', 'answer', 'authorised_until',
    'paid_at', 'fulfilled_at', 'refunded_at', 'download_count',
])]
class Order extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount_minor' => 'integer',
            'download_count' => 'integer',
            'authorised_until' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return HasMany<OrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->latest('id');
    }

    /**
     * @return HasOne<Booking, $this>
     */
    public function booking(): HasOne
    {
        return $this->hasOne(Booking::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordEvent(string $type, array $data = []): OrderEvent
    {
        return $this->events()->create([
            'type' => $type,
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ]);
    }

    /**
     * A download link may be issued: paid (or delivered) and not refunded.
     */
    public function canDownload(): bool
    {
        return $this->status->isPaid() && $this->refunded_at === null;
    }
}
