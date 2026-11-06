<?php

namespace App\Actions\Store;

use App\Contracts\PaymentProvider;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Exceptions\Store\CheckoutUnavailableException;
use App\Models\Booking;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Services\Store\BookingSlotService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.2 / CG-4.4 / CG-4.5 — turn a buyer's intent into a pending {@see Order} and a hosted
 * checkout URL on the creator's own Stripe account (a direct charge, no application fee — Principle 3).
 *
 * Everything that can refuse the sale refuses it *here*, before any money moves: the product must be
 * on sale, the creator's account must be able to take charges, a tip must clear the minimum, a
 * coupon must be live and in scope, and a booking slot must still be free. Each refusal is a
 * {@see CheckoutUnavailableException} the public page shows as a sentence.
 *
 * The slot and the coupon redemption are both claimed at *this* point, inside the transaction, so
 * two buyers racing for the last slot (or the last use of a code) cannot both win. An abandoned
 * checkout therefore holds them until `ExpireStaleOrdersAction` releases it.
 */
class CreateCheckoutAction
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly BookingSlotService $slots,
    ) {}

    /**
     * @param  array<string, mixed>  $data  `buyer_email`, `buyer_name`, `amount_minor` (tips),
     *                                      `coupon_code`, `message`, `slot` (bookings)
     * @param  string  $handle  the brand's bio handle — the store lives on it, and the return URLs
     *                          can only be built once the order (and so its id) exists
     * @return array{order: Order, url: string}
     */
    public function __invoke(Product $product, array $data, string $handle): array
    {
        if (! $product->isBuyable()) {
            throw new CheckoutUnavailableException(__('This is not on sale right now.'));
        }

        $account = PaymentAccount::query()->withoutOrgScope()->where('org_id', $product->org_id)->first();

        if ($account === null || ! $account->canSell()) {
            throw new CheckoutUnavailableException(__('This shop cannot take payments yet. Try again soon.'));
        }

        $slot = $this->resolveSlot($product, $data);

        $order = DB::transaction(function () use ($product, $data, $slot): Order {
            $amount = $this->amount($product, $data);
            $coupon = $this->claimCoupon($product, $data['coupon_code'] ?? null);

            if ($coupon !== null) {
                $amount = $coupon->applyTo($amount);
            }

            $order = Order::create([
                'org_id' => $product->org_id,
                'product_id' => $product->id,
                'coupon_id' => $coupon?->id,
                'buyer_email' => strtolower(trim((string) $data['buyer_email'])),
                'buyer_name' => $this->clean($data['buyer_name'] ?? null),
                'amount_minor' => $amount,
                'currency' => strtoupper($product->currency),
                'status' => OrderStatus::Pending,
                'message' => $this->clean($data['message'] ?? null),
                // A paid question is only authorised at checkout; this is how long we may hold it.
                'authorised_until' => $product->type->isAuthorisedThenCaptured()
                    ? now()->addHours($product->answerWindowHours())
                    : null,
            ]);

            if ($slot !== null) {
                // The unique index on (product_id, starts_at) is the real guard; this insert is what
                // claims the slot for this buyer.
                Booking::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'starts_at' => $slot,
                    'ends_at' => $slot->addMinutes($this->slots->availability($product)['slot_minutes']),
                ]);
            }

            $order->recordEvent('checkout.started', array_filter([
                'coupon_id' => $coupon?->id,
                'slot' => $slot?->toIso8601String(),
            ]));

            return $order;
        });

        $checkout = $this->provider->createCheckout(
            $account,
            $order,
            route('store.thanks', ['handle' => $handle, 'order' => $order->id]),
            route('store.product', ['handle' => $handle, 'product' => $product->id]),
        );

        $order->update(['external_session_id' => $checkout['id']]);

        Log::info('Checkout started', [
            'order_id' => $order->id,
            'org_id' => $order->org_id,
            'product_id' => $product->id,
            'type' => $product->type->value,
        ]);

        return ['order' => $order, 'url' => $checkout['url']];
    }

    /**
     * What the buyer will be charged, in minor units. A tip is the buyer's number (floored at the
     * product's minimum); everything else is the product's price. Below Stripe's charge floor there
     * is no sale to make.
     *
     * @param  array<string, mixed>  $data
     */
    private function amount(Product $product, array $data): int
    {
        $amount = $product->type->buyerSetsAmount()
            ? max((int) ($data['amount_minor'] ?? 0), (int) $product->setting('min_minor', $product->price_minor))
            : $product->price_minor;

        if ($amount < Product::MIN_CHARGE_MINOR) {
            throw new CheckoutUnavailableException(__('That amount is too small to charge a card.'));
        }

        return $amount;
    }

    /**
     * Lock the coupon row, check it is live and in scope, and claim one redemption. Locking is what
     * makes `max_redemptions` hold under concurrent checkouts.
     */
    private function claimCoupon(Product $product, mixed $code): ?Coupon
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        if ($code === '') {
            return null;
        }

        $coupon = Coupon::query()
            ->withoutOrgScope()
            ->where('org_id', $product->org_id)
            ->where('code', $code)
            ->lockForUpdate()
            ->first();

        if ($coupon === null || ! $coupon->appliesTo($product) || $coupon->isExpired() || $coupon->isExhausted()) {
            throw new CheckoutUnavailableException(__('That code is not valid for this.'));
        }

        $coupon->increment('redemptions');

        return $coupon;
    }

    /**
     * The booking instant the buyer picked, re-checked against live availability (the list they saw
     * may be minutes old), or null for a product that is not a booking.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSlot(Product $product, array $data): ?CarbonImmutable
    {
        if ($product->type !== ProductType::Booking) {
            return null;
        }

        $raw = (string) ($data['slot'] ?? '');

        if ($raw === '') {
            throw new CheckoutUnavailableException(__('Pick a time first.'));
        }

        $slot = CarbonImmutable::parse($raw)->utc();

        if (! $this->slots->isBookable($product, $slot)) {
            throw new CheckoutUnavailableException(__('That time has just gone — pick another.'));
        }

        return $slot;
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
