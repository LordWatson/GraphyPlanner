<?php

namespace App\Actions\Store;

use App\Enums\OrderStatus;
use App\Enums\WebhookEvent;
use App\Models\Order;
use App\Services\Api\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.2 — the webhook side of a sale: Stripe says the checkout completed, so the order becomes
 * paid and is fulfilled.
 *
 * Three things this is careful about, because webhooks are neither ordered nor delivered once:
 *
 *  - **Replays are no-ops.** An order that is not `pending` is logged and left alone, so the same
 *    `checkout.session.completed` arriving twice never double-delivers or double-captures.
 *  - **The amount must match.** If the session's total or currency differs from the order we
 *    created, the order is *not* marked paid; the mismatch is recorded for a human instead. This is
 *    what stops a tampered or mis-routed session paying for the wrong thing.
 *  - **Fulfilment is inside the same transaction as the status change**, so a failure to issue a
 *    download link cannot leave an order that looks delivered.
 */
class MarkOrderPaidAction
{
    public function __construct(
        private readonly TransitionOrderStatusAction $transition,
        private readonly FulfilOrderAction $fulfil,
    ) {}

    /**
     * @param  array<string, mixed>  $session  the provider's checkout session object
     */
    public function __invoke(Order $order, array $session): Order
    {
        if ($order->status !== OrderStatus::Pending) {
            Log::info('Order already resolved; ignoring duplicate payment event', [
                'order_id' => $order->id,
                'status' => $order->status->value,
            ]);

            return $order;
        }

        $total = $session['amount_total'] ?? null;
        $currency = isset($session['currency']) ? strtoupper((string) $session['currency']) : null;

        if (($total !== null && (int) $total !== $order->amount_minor) || ($currency !== null && $currency !== $order->currency)) {
            $order->recordEvent('payment.mismatch', [
                'expected_minor' => $order->amount_minor,
                'expected_currency' => $order->currency,
                'received_minor' => $total === null ? null : (int) $total,
                'received_currency' => $currency,
            ]);

            Log::error('Checkout amount/currency mismatch; order left pending', [
                'order_id' => $order->id,
                'org_id' => $order->org_id,
            ]);

            return $order;
        }

        return DB::transaction(function () use ($order, $session): Order {
            $paymentId = $session['payment_intent'] ?? null;

            if (is_string($paymentId) && $paymentId !== '') {
                $order->update(['external_payment_id' => $paymentId]);
            }

            ($this->transition)($order, OrderStatus::Paid, ['session' => $order->external_session_id]);

            $fulfilled = ($this->fulfil)($order->refresh());

            // Outgoing webhook, after the commit so nothing is announced that a rollback un-did.
            // `order.paid` was in `WebhookEvent` with nothing emitting it, which is the one thing
            // worse than not offering an event at all: an integration subscribes and waits forever.
            DB::afterCommit(function () use ($fulfilled): void {
                $organization = $fulfilled->organization;

                if ($organization !== null) {
                    app(WebhookDispatcher::class)->dispatch($organization, WebhookEvent::OrderPaid, [
                        'order_id' => $fulfilled->id,
                        'product_id' => $fulfilled->product_id,
                        'amount_minor' => $fulfilled->amount_minor,
                        'currency' => $fulfilled->currency,
                        'buyer_email' => $fulfilled->buyer_email,
                    ]);
                }
            });

            return $fulfilled;
        });
    }
}
