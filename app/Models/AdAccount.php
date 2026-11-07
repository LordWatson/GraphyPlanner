<?php

namespace App\Models;

use App\Contracts\AdsSource;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Step GTM-5.1 — one paid-ads account a brand's spend is reported from. **Reporting only**: there
 * is no budget, no campaign and no write path anywhere in this module, by construction (see
 * {@see AdsSource}).
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property Platform $platform
 * @property string $external_account_id
 * @property string|null $name
 * @property string|null $currency
 * @property ConnectionStatus $connection_status
 * @property Carbon|null $metrics_synced_at
 * @property string|null $metrics_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AdAccountMetric> $metrics
 */
#[Fillable([
    'org_id', 'client_id', 'platform', 'external_account_id', 'name', 'currency',
    'connection_status', 'metrics_synced_at', 'metrics_error',
])]
class AdAccount extends Model
{
    use BelongsToOrganization;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'connection_status' => ConnectionStatus::class,
            'metrics_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return HasMany<AdAccountMetric, $this>
     */
    public function metrics(): HasMany
    {
        return $this->hasMany(AdAccountMetric::class);
    }

    public function isConnected(): bool
    {
        return $this->connection_status === ConnectionStatus::Connected;
    }

    public function displayName(): string
    {
        return $this->name ?: $this->platform->label().' '.$this->external_account_id;
    }
}
