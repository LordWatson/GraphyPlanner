<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $website
 * @property string|null $industry
 * @property array<int, string>|null $countries
 * @property ClientStatus $status
 * @property int|null $owner_user_id
 * @property Carbon|null $start_date
 * @property string|null $retainer_amount
 * @property BillingCycle|null $billing_cycle
 * @property array<int, string>|null $tags
 * @property string|null $default_language
 * @property string|null $notes_internal
 * @property string|null $approval_email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'name', 'legal_name', 'website', 'industry', 'countries', 'status',
    'owner_user_id', 'start_date', 'retainer_amount', 'billing_cycle', 'tags',
    'default_language', 'notes_internal', 'approval_email',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'tags' => 'array',
            'status' => ClientStatus::class,
            'billing_cycle' => BillingCycle::class,
            'start_date' => 'date',
            'retainer_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * @return HasOne<BrandBrain, $this>
     */
    public function brandBrain(): HasOne
    {
        return $this->hasOne(BrandBrain::class);
    }

    /**
     * Computed (not persisted) health status/reason. See `App\Services\ClientHealthService`.
     *
     * @return array{status: \App\Enums\ClientHealth, reason: string}
     */
    public function health(): array
    {
        return app(\App\Services\ClientHealthService::class)->compute($this);
    }
}
