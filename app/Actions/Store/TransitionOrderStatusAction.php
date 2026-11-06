<?php

namespace App\Actions\Store;

use App\Enums\OrderStatus;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.2 — the one place an order's status changes. {@see OrderStatus::canTransitionTo()} is
 * the map; anything else throws, so a late or replayed webhook can't walk a refunded order back to
 * paid. Every move writes an `order_events` row in the same transaction, which is the audit trail
 * for a money record.
 *
 * Callers that *expect* to be re-run (webhooks) check `$order->status` first and skip; this Action
 * is deliberately strict rather than forgiving.
 */
class TransitionOrderStatusAction
{
    /**
     * @param  array<string, mixed>  $context  extra detail for the audit row (never card data)
     */
    public function __invoke(Order $order, OrderStatus $to, array $context = []): Order
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw new DomainException(sprintf('An order that is %s cannot become %s.', $from->value, $to->value));
        }

        return DB::transaction(function () use ($order, $from, $to, $context): Order {
            $order->update([
                'status' => $to,
                ...match ($to) {
                    OrderStatus::Paid => ['paid_at' => $order->paid_at ?? now()],
                    OrderStatus::Fulfilled => ['fulfilled_at' => now()],
                    OrderStatus::Refunded => ['refunded_at' => now()],
                    default => [],
                },
            ]);

            $order->recordEvent('status.'.$to->value, ['from' => $from->value, ...$context]);

            Log::info('Order status changed', [
                'order_id' => $order->id,
                'org_id' => $order->org_id,
                'product_id' => $order->product_id,
                'from' => $from->value,
                'to' => $to->value,
            ]);

            return $order;
        });
    }
}
