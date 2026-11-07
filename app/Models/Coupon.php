<?php

namespace App\Models;

use App\Actions\Store\CreateCheckoutAction;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.4 — a discount code. Percent or fixed amount off, optionally scoped to one product,
 * optionally expiring, optionally capped at N redemptions. The cap is enforced with a row lock in
 * {@see CreateCheckoutAction} — two buyers racing for the last use of a code
 * can't both get it.
 *
 * A redemption is claimed when checkout *starts*, so it has to be handed back when the sale does
 * not complete — see {@see releaseRedemption()}.
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $product_id
 * @property string $code
 * @property int|null $percent_off
 * @property int|null $amount_off_minor
 * @property Carbon|null $expires_at
 * @property int|null $max_redemptions
 * @property int $redemptions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'product_id', 'code', 'percent_off', 'amount_off_minor', 'expires_at',
    'max_redemptions', 'redemptions',
])]
class Coupon extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'redemptions' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->isBefore($now ?? now());
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null && $this->redemptions >= $this->max_redemptions;
    }

    /**
     * Hand back the redemption this coupon's order claimed, because the sale did not happen: the
     * checkout was abandoned and swept up, or the order was refunded.
     *
     * A redemption is claimed when a buyer *starts* checkout, before any money moves, so without
     * this a capped code is spent by people who changed their mind — fifty abandoned tabs killed
     * a `max_redemptions = 50` code for the fifty-first buyer, the one who would have paid.
     *
     * Done in SQL, floored at zero, so a double release (a refund arriving from both `/store` and
     * the Stripe webhook) cannot push the count negative and hand out free uses.
     */
    public function releaseRedemption(): void
    {
        static::query()
            // Called from the hourly sweep and the Stripe webhook, neither of which has a
            // signed-in member for the org scope to read.
            ->withoutOrgScope()
            ->whereKey($this->getKey())
            ->where('redemptions', '>', 0)
            ->decrement('redemptions');
    }

    public function appliesTo(Product $product): bool
    {
        return $this->product_id === null || $this->product_id === $product->id;
    }

    /**
     * The discounted amount, never below zero and never below Stripe's charge floor (a 100% code on
     * a paid product would otherwise produce a charge Stripe refuses).
     */
    public function applyTo(int $amountMinor): int
    {
        $discounted = $this->percent_off !== null
            ? (int) round($amountMinor * (100 - $this->percent_off) / 100)
            : $amountMinor - (int) $this->amount_off_minor;

        return max(Product::MIN_CHARGE_MINOR, $discounted);
    }

    public function label(): string
    {
        return $this->percent_off !== null ? $this->percent_off.'% off' : 'Fixed amount off';
    }
}
