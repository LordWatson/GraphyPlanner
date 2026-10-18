<?php

namespace App\Models;

use App\Enums\ClientHealth;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step I-2.5 — one change in a client's stored health, written by `RecomputeClientHealthAction`.
 * Feeds the health-over-time sparkline (Step I-6.1). Reached through its client.
 *
 * @property int $id
 * @property int $client_id
 * @property ClientHealth $health_status
 * @property string $health_reason
 * @property Carbon|null $created_at
 */
#[Fillable(['client_id', 'health_status', 'health_reason', 'created_at'])]
class ClientHealthHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'client_health_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'health_status' => ClientHealth::class,
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
