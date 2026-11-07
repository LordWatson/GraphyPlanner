<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Where an organization wants to be told about things, and what it wants to be told about.
 *
 * The secret is `encrypted` and `Hidden`: it is shown once, when the endpoint is created, and
 * never again. Anyone holding it can forge a delivery the receiver will trust, so it must not reach
 * an API response, an Inertia page or a log line.
 *
 * @property int $id
 * @property int $org_id
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property string|null $description
 * @property Carbon|null $disabled_at
 * @property string|null $disabled_reason
 */
#[Fillable(['org_id', 'url', 'secret', 'events', 'description', 'disabled_at', 'disabled_reason'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    use BelongsToOrganization;

    /**
     * Consecutive failures after which an endpoint is switched off.
     *
     * Needed because a dead URL otherwise keeps a queue busy forever: every event for every post
     * retried against a host that stopped existing months ago. Switching it off and saying why is
     * more useful than retrying into the void, and the deliveries are kept so they can be replayed
     * once somebody fixes the URL.
     */
    public const FAILURES_BEFORE_DISABLE = 20;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null;
    }

    public function wants(string $event): bool
    {
        return $this->isActive() && in_array($event, $this->events, true);
    }
}
