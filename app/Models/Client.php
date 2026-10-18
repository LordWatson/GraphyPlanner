<?php

namespace App\Models;

use App\Actions\Clients\RecomputeClientHealthAction;
use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Services\ClientHealthService;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * @property string|null $billing_email
 * @property string|null $billing_address
 * @property ClientHealth|null $health_status
 * @property string|null $health_reason
 * @property Carbon|null $health_computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'name', 'legal_name', 'website', 'industry', 'countries', 'status',
    'owner_user_id', 'start_date', 'retainer_amount', 'billing_cycle', 'tags',
    'default_language', 'notes_internal', 'approval_email', 'billing_email', 'billing_address',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

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
            'health_status' => ClientHealth::class,
            'health_computed_at' => 'datetime',
        ];
    }

    /**
     * Attributes `ClientHealthService`'s rules read — changing one re-materializes health.
     *
     * @var list<string>
     */
    public const HEALTH_INPUTS = ['status', 'owner_user_id', 'start_date', 'retainer_amount', 'billing_cycle'];

    protected static function booted(): void
    {
        // Step I-2.5: keep `clients.health_*` current. The action saves quietly, so no loop.
        static::saved(function (self $client) {
            if (($client->wasRecentlyCreated && $client->health_computed_at === null) || $client->wasChanged(self::HEALTH_INPUTS)) {
                app(RecomputeClientHealthAction::class)($client);
            }
        });
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
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Where invoices for this client are emailed: the dedicated billing contact, falling back to
     * the approval contact when no billing email has been set.
     */
    public function invoiceRecipientEmail(): ?string
    {
        return $this->billing_email ?: $this->approval_email;
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * @return HasMany<ClientInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ClientInvitation::class);
    }

    /**
     * @return HasMany<AiTrend, $this>
     */
    public function aiTrends(): HasMany
    {
        return $this->hasMany(AiTrend::class);
    }

    /**
     * @return HasMany<AiPostIdea, $this>
     */
    public function aiPostIdeas(): HasMany
    {
        return $this->hasMany(AiPostIdea::class);
    }

    /**
     * Health status/reason. Step I-2.5: read from the materialized columns kept current by
     * `RecomputeClientHealthAction`; computed live (see `ClientHealthService`) only for a row that
     * has never been computed.
     *
     * @return array{status: ClientHealth, reason: string}
     */
    public function health(): array
    {
        if ($this->health_status instanceof ClientHealth && $this->health_reason !== null) {
            return ['status' => $this->health_status, 'reason' => $this->health_reason];
        }

        return app(ClientHealthService::class)->compute($this);
    }

    /**
     * @return HasMany<ClientHealthHistory, $this>
     */
    public function healthHistory(): HasMany
    {
        return $this->hasMany(ClientHealthHistory::class);
    }
}
