<?php

namespace App\Listeners;

use App\Actions\Billing\SyncSubscriptionAction;
use App\Models\Organization;
use App\Models\StripeWebhookEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Step CG-2.4 / B-9.3 — after Cashier has handled a Stripe webhook (and already upserted its own
 * subscription rows), reconcile `organizations.plan` via {@see SyncSubscriptionAction}. Idempotent:
 * every processed event id is recorded in `stripe_webhook_events`, so a replayed delivery is skipped.
 */
class SyncSubscriptionOnStripeWebhook
{
    public function __construct(private readonly SyncSubscriptionAction $sync) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;

        $eventId = Arr::get($payload, 'id');
        $type = (string) Arr::get($payload, 'type', '');

        if ($eventId === null) {
            return;
        }

        // Only subscription/invoice events change what plan an org is on.
        if (! str_starts_with($type, 'customer.subscription.') && ! str_starts_with($type, 'invoice.')) {
            return;
        }

        // Idempotency: a replayed event is a no-op.
        if (StripeWebhookEvent::query()->where('stripe_event_id', $eventId)->exists()) {
            Log::info('Skipping already-processed Stripe event', ['stripe_event_id' => $eventId, 'type' => $type]);

            return;
        }

        $customerId = Arr::get($payload, 'data.object.customer');

        if (is_string($customerId) && $customerId !== '') {
            // The billable is always an Organization (Cashier::useCustomerModel); resolve it directly
            // for a typed result.
            $organization = Organization::query()->where('stripe_id', $customerId)->first();

            if ($organization !== null) {
                ($this->sync)($organization);
            }
        }

        // Record the processed event. insertOrIgnore (not create) so a concurrent/retried delivery
        // that slipped past the exists() check above can't raise a unique-constraint 500 — the sync
        // itself is idempotent, so the duplicate is simply a no-op.
        $now = now();

        StripeWebhookEvent::query()->insertOrIgnore([
            'stripe_event_id' => $eventId,
            'type' => $type,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
