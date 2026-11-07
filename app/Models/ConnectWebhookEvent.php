<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.1 — one Stripe **Connect** webhook event, keyed on Stripe's event id so a replay is
 * detected and skipped. Deliberately its own table rather than Cashier's `stripe_webhook_events`
 * (Principle 4: the subscription and the sales integrations share no tables, webhooks or secrets).
 *
 * The row is a *claim* first and a *completion* second: it is inserted with a null `processed_at`
 * before the event is handled (the unique index on `stripe_event_id` is what stops two
 * simultaneous deliveries both processing it), stamped once the handler returns, and deleted if
 * the handler throws — so Stripe's retry of a failed delivery is not mistaken for a replay. See
 * `ConnectWebhookController`.
 *
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property Carbon|null $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['stripe_event_id', 'type', 'processed_at'])]
class ConnectWebhookEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }
}
