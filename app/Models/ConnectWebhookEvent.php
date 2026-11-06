<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Step CG-4.1 — one processed Stripe **Connect** webhook event, keyed on Stripe's event id so a
 * replay is detected and skipped. Deliberately its own table rather than Cashier's
 * `stripe_webhook_events` (Principle 4: the subscription and the sales integrations share no
 * tables, webhooks or secrets).
 *
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property Carbon $processed_at
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
