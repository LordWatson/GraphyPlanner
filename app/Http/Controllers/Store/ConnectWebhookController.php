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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *
 * **A claim is not a completion.** The row is written with a null `processed_at`, stamped only once
 * the handler has returned, and deleted if the handler throws. That ordering matters: the claim
 * used to be stamped up front, so a handler that failed (a database hiccup, an unreachable mail
 * driver, a bug in fulfilment) returned a 500 and Stripe's retry was then dismissed as a replay —
 * a charged card with an order stuck `pending` and no deliveries left to come. For the same
 * reason only a genuine unique-constraint violation counts as a replay; every other database error
 * is allowed to surface as a 500 that Stripe will retry.
 */
class ConnectWebhookController extends Controller
{
    /**
     * How long an unfinished claim is assumed to belong to a delivery that is still working. Past
     * it, the claim is taken over — the only way a worker killed between the insert and the
     * release can be recovered from.
     */
    private const STALE_CLAIM_MINUTES = 5;

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

        try {
            match ($type) {
                'account.updated' => $this->accountUpdated($object, $syncAccount),
                'checkout.session.completed' => $this->checkoutCompleted($object, $markPaid),
                'charge.refunded' => $this->chargeRefunded($object),
                default => Log::info('Connect webhook: ignoring event', ['type' => $type]),
            };
        } catch (Throwable $e) {
            // Release the claim so Stripe's retry is a real second attempt and not a "replay".
            $this->release($eventId);

            Log::error('Connect webhook: handler failed, claim released for retry', [
                'event_id' => $eventId,
                'type' => $type,
                'exception' => $e::class,
            ]);

            throw $e;
        }

        ConnectWebhookEvent::query()->where('stripe_event_id', $eventId)->update(['processed_at' => now()]);

        return response()->json(['received' => true]);
    }

    /**
     * Take the claim on this event, or report that someone else holds it. The unique index is the
     * lock; `processed_at` is what distinguishes "already done" from "someone is doing it".
     */
    private function claim(string $eventId, string $type): bool
    {
        try {
            ConnectWebhookEvent::create(['stripe_event_id' => $eventId, 'type' => $type]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Only a duplicate id lands here. Every other QueryException is left to surface as a
            // 500 Stripe will retry, rather than being silently read as "already handled".
        }

        // A claim with no completion, old enough that whoever took it is not still working on it:
        // the process died between the insert and the release. Process it rather than drop a sale.
        // Handlers are idempotent (`MarkOrderPaidAction` no-ops on an order that is not pending),
        // so the rare double take-over is safe.
        $stale = ConnectWebhookEvent::query()
            ->where('stripe_event_id', $eventId)
            ->whereNull('processed_at')
            ->where('created_at', '<', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->exists();

        if ($stale) {
            Log::warning('Connect webhook: taking over an abandoned claim', ['event_id' => $eventId, 'type' => $type]);
        }

        return $stale;
    }

    /**
     * Drop an unfinished claim. Scoped to `processed_at is null` so it can never delete the record
     * of an event that really was handled.
     */
    private function release(string $eventId): void
    {
        ConnectWebhookEvent::query()
            ->where('stripe_event_id', $eventId)
            ->whereNull('processed_at')
            ->delete();
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
        // Same release as `RefundOrderAction`; `releaseRedemption()` is floored at zero so a refund
        // arriving from both paths cannot hand out a free use.
        $order->coupon?->releaseRedemption();
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
