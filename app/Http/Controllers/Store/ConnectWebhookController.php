<?php

namespace App\Http\Controllers\Store;

use App\Actions\Store\MarkOrderPaidAction;
use App\Actions\Store\SyncPaymentAccountAction;
use App\Actions\Store\TransitionOrderStatusAction;
use App\Contracts\PaymentProvider;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\ConnectWebhookEvent;
use App\Models\Order;
use App\Models\PaymentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Step CG-4.1 / CG-4.2 — Stripe Connect's webhook endpoint. Its own URL and its own signing secret,
 * separate from Cashier's subscription webhook (Principle 4): a delivery signed for one is rejected
 * by the other.
 *
 * Three handled events; everything else is acknowledged and ignored so Stripe stops retrying:
 *
 *  - `account.updated` — the creator's capabilities changed (onboarding finished, documents due,
 *    account disabled). Read straight off the event, so no API call is needed.
 *  - `checkout.session.completed` — a sale. Handed to {@see MarkOrderPaidAction}, which is itself
 *    idempotent and verifies the amount.
 *  - `charge.refunded` — a refund made at Stripe rather than from `/store`, so the order follows.
 *
 * Idempotency is a unique `connect_webhook_events.stripe_event_id`: the insert is the claim, so two
 * simultaneous deliveries of one event can't both be processed. The endpoint is unauthenticated (a
 * server-to-server call has no session) and CSRF-exempt, exactly like the Upload-Post webhook.
 */
class ConnectWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentProvider $provider,
        MarkOrderPaidAction $markPaid,
        SyncPaymentAccountAction $syncAccount,
    ): JsonResponse {
        $event = $provider->verifyWebhook($request->getContent(), $request->header('Stripe-Signature'));

        if ($event === null) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $eventId = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($eventId === '' || $type === '') {
            return response()->json(['message' => 'Malformed event'], 422);
        }

        if (! $this->claim($eventId, $type)) {
            Log::info('Connect webhook: replay ignored', ['event_id' => $eventId, 'type' => $type]);

            return response()->json(['received' => true]);
        }

        /** @var array<string, mixed> $object */
        $object = $event['data']['object'] ?? [];

        match ($type) {
            'account.updated' => $this->accountUpdated($object, $syncAccount),
            'checkout.session.completed' => $this->checkoutCompleted($object, $markPaid),
            'charge.refunded' => $this->chargeRefunded($object),
            default => Log::info('Connect webhook: ignoring event', ['type' => $type]),
        };

        return response()->json(['received' => true]);
    }

    /**
     * Record the event id, or report that someone else already has it. The unique index is the lock.
     */
    private function claim(string $eventId, string $type): bool
    {
        try {
            ConnectWebhookEvent::create([
                'stripe_event_id' => $eventId,
                'type' => $type,
                'processed_at' => now(),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function accountUpdated(array $object, SyncPaymentAccountAction $sync): void
    {
        $account = PaymentAccount::query()
            ->withoutOrgScope()
            ->where('external_account_id', (string) ($object['id'] ?? ''))
            ->first();

        if ($account === null) {
            Log::info('Connect webhook: account.updated for an unknown account');

            return;
        }

        $sync($account, SyncPaymentAccountAction::statusFromWebhookObject($object));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function checkoutCompleted(array $object, MarkOrderPaidAction $markPaid): void
    {
        $order = $this->orderFor($object);

        if ($order === null) {
            Log::warning('Connect webhook: checkout completed for an unknown order');

            return;
        }

        $markPaid($order, $object);
    }

    /**
     * A refund taken at Stripe: mirror it here so `/store` and the buyer's download agree with the
     * money. Already-refunded orders are left alone.
     *
     * @param  array<string, mixed>  $object
     */
    private function chargeRefunded(array $object): void
    {
        $paymentId = (string) ($object['payment_intent'] ?? '');

        $order = $paymentId === '' ? null : Order::query()
            ->withoutOrgScope()
            ->where('external_payment_id', $paymentId)
            ->first();

        if ($order === null || ! $order->status->canTransitionTo(OrderStatus::Refunded)) {
            return;
        }

        app(TransitionOrderStatusAction::class)($order, OrderStatus::Refunded, ['source' => 'webhook']);
        $order->booking?->delete();
    }

    /**
     * The order a session belongs to: by our own session id first, then by the metadata we set when
     * creating it (so a session re-created at Stripe still lands on the right order).
     *
     * @param  array<string, mixed>  $object
     */
    private function orderFor(array $object): ?Order
    {
        $sessionId = (string) ($object['id'] ?? '');

        $order = $sessionId === '' ? null : Order::query()
            ->withoutOrgScope()
            ->where('external_session_id', $sessionId)
            ->first();

        if ($order !== null) {
            return $order;
        }

        $orderId = $object['metadata']['order_id'] ?? $object['client_reference_id'] ?? null;

        return is_numeric($orderId)
            ? Order::query()->withoutOrgScope()->find((int) $orderId)
            : null;
    }
}
