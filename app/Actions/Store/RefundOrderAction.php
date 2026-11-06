<?php

namespace App\Actions\Store;

use App\Contracts\PaymentProvider;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.6 — refund a sale in full. The provider is asked first and the order is only marked
 * refunded once it agrees, so a failed refund never leaves an order claiming the buyer has their
 * money back.
 *
 * Marking it refunded is also what revokes delivery: {@see Order::canDownload()} is false from that
 * moment, so an unexpired download link the buyer still has stops working, and the booking slot is
 * released back into availability.
 */
class RefundOrderAction
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly TransitionOrderStatusAction $transition,
    ) {}

    public function __invoke(Order $order, ?User $actor = null): Order
    {
        if (! $order->status->canTransitionTo(OrderStatus::Refunded)) {
            throw new DomainException(sprintf('A %s order cannot be refunded.', $order->status->value));
        }

        $account = PaymentAccount::query()->withoutOrgScope()->where('org_id', $order->org_id)->firstOrFail();

        $this->provider->refund($account, $order);

        return DB::transaction(function () use ($order, $actor): Order {
            $order = ($this->transition)($order, OrderStatus::Refunded, ['by_user_id' => $actor?->id]);

            // Free the slot: a refunded booking is not a booking any more.
            $order->booking?->delete();

            Log::info('Order refunded', [
                'order_id' => $order->id,
                'org_id' => $order->org_id,
                'user_id' => $actor?->id,
            ]);

            return $order;
        });
    }
}
