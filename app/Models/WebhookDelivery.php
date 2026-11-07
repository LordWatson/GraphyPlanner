<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to tell somebody something, and how it went.
 *
 * Kept rather than fired and forgotten: the first question about a webhook is always "did it
 * arrive, and if not why", and the second is "send it again". A row answers both, and is what a
 * dead-letter list is built from.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property int $org_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 */
#[Fillable([
    'webhook_endpoint_id', 'org_id', 'event', 'payload', 'attempts', 'response_status',
    'error', 'delivered_at', 'failed_at',
])]
class WebhookDelivery extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function isPending(): bool
    {
        return $this->delivered_at === null && $this->failed_at === null;
    }
}
