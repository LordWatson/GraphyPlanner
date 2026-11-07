<?php

namespace App\Actions\Store;

use App\Contracts\PaymentProvider;
use App\Enums\AudienceTrigger;
use App\Enums\OrderStatus;
use App\Jobs\SendAudienceTriggerJob;
use App\Models\AudienceContact;
use App\Models\Order;
use App\Models\PaymentAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step CG-4.5 — the housekeeping that keeps a store honest, run hourly by `store:expire-orders`.
 *
 * Two kinds of stale order:
 *
 *  - **Abandoned checkouts.** A pending order holds its booking slot *and* a coupon redemption from
 *    the moment the buyer is sent to Stripe. After an hour it is cancelled and both are released, so
 *    one abandoned tab cannot sit on a Friday afternoon forever — nor can fifty of them spend a
 *    capped discount code that nobody ever paid with.
 *  - **Unanswered paid questions.** The card was authorised, never captured. Past
 *    `authorised_until` the authorisation is released at the provider and the order cancelled —
 *    deliberately well inside the ~7 days a card authorisation survives, so we never try to capture
 *    something that has already lapsed.
 *
 * Each order is handled on its own: a provider failure on one is logged and the rest still run.
 *
 * @phpstan-type ExpiryResult array{cancelled: int, released: int}
 */
class ExpireStaleOrdersAction
{
    /**
     * How long a pending checkout may hold its slot.
     */
    private const PENDING_TTL_MINUTES = 60;

    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly TransitionOrderStatusAction $transition,
    ) {}

    /**
     * @return array{cancelled: int, released: int}
     */
    public function __invoke(): array
    {
        return [
            'cancelled' => $this->cancelAbandoned(),
            'released' => $this->releaseUnanswered(),
        ];
    }

    private function cancelAbandoned(): int
    {
        $orders = Order::query()
            ->withoutOrgScope()
            ->where('status', OrderStatus::Pending)
            ->where('created_at', '<', now()->subMinutes(self::PENDING_TTL_MINUTES))
            ->with('product', 'coupon')
            ->limit(500)
            ->get();

        foreach ($orders as $order) {
            ($this->transition)($order, OrderStatus::Cancelled, ['reason' => 'abandoned']);
            $order->booking?->delete();
            // The slot is not the only thing an abandoned checkout was holding.
            $order->coupon?->releaseRedemption();
            $this->nudgeAbandoned($order);
        }

        return $orders->count();
    }

    /**
     * Step GTM-2.2 — the abandoned-checkout email. Sent only once the order is actually cancelled,
     * which is also why there is nothing to cancel if the buyer completes: a completed order never
     * reaches this sweep. The job's own checks run at delivery.
     */
    private function nudgeAbandoned(Order $order): void
    {
        $contact = AudienceContact::query()
            ->withoutOrgScope()
            ->where('client_id', $order->product->client_id)
            ->where('email', $order->buyer_email)
            ->first();

        if ($contact !== null) {
            SendAudienceTriggerJob::dispatch($contact->id, AudienceTrigger::Abandoned, (string) $order->id);
        }
    }

    private function releaseUnanswered(): int
    {
        $orders = Order::query()
            ->withoutOrgScope()
            ->where('status', OrderStatus::Paid)
            ->whereNotNull('authorised_until')
            ->where('authorised_until', '<', now())
            ->limit(500)
            ->get();

        $released = 0;

        foreach ($orders as $order) {
            $account = PaymentAccount::query()->withoutOrgScope()->where('org_id', $order->org_id)->first();

            try {
                if ($account !== null) {
                    $this->provider->cancelAuthorisation($account, $order);
                }

                ($this->transition)($order, OrderStatus::Cancelled, ['reason' => 'unanswered']);
                $released++;
            } catch (Throwable $e) {
                Log::error('Could not release an unanswered question authorisation', [
                    'order_id' => $order->id,
                    'org_id' => $order->org_id,
                    'exception' => $e::class,
                ]);
            }
        }

        return $released;
    }
}
