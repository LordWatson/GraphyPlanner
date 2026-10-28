<?php

namespace App\Models;

use Database\Factories\StripeWebhookEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Phase CG-2 (Step CG-2.4) — one processed Stripe webhook event, keyed on Stripe's event id, so a
 * replayed delivery is detected and skipped (idempotency).
 *
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property Carbon $processed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['stripe_event_id', 'type', 'processed_at'])]
class StripeWebhookEvent extends Model
{
    /** @use HasFactory<StripeWebhookEventFactory> */
    use HasFactory;

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
