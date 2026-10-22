<?php

namespace App\Models;

use App\Actions\Clients\RecomputeClientHealthAction;
use App\Enums\ApprovalMode;
use App\Enums\BillingCycle;
use App\Enums\ClientHealth;
use App\Enums\ClientReportSchedule;
use App\Enums\ClientStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
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
 * @property ApprovalMode $default_approval_mode
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
 * @property string|null $tax_registration_number
 * @property string|null $billing_currency
 * @property int|null $default_tax_rate_id
 * @property bool $auto_invoice_retainer
 * @property ClientHealth|null $health_status
 * @property string|null $health_reason
 * @property list<array{key: string, tone: string, weight: int, label: string, owner_only?: bool}>|null $health_reasons
 * @property Carbon|null $health_computed_at
 * @property int $approval_lead_days
 * @property int|null $target_posts_per_week
 * @property ClientReportSchedule $report_schedule
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'name', 'legal_name', 'website', 'industry', 'countries', 'status',
    'owner_user_id', 'start_date', 'retainer_amount', 'billing_cycle', 'tags',
    'default_language', 'notes_internal', 'approval_email', 'billing_email', 'billing_address',
    'tax_registration_number', 'billing_currency', 'default_tax_rate_id', 'auto_invoice_retainer',
    'approval_lead_days', 'default_approval_mode', 'report_schedule', 'target_posts_per_week',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        // Step M-3.3: mirrors the column default.
        'report_schedule' => 'review',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'tags' => 'array',
            'status' => ClientStatus::class,
            'default_approval_mode' => ApprovalMode::class,
            'billing_cycle' => BillingCycle::class,
            'start_date' => 'date',
            'retainer_amount' => 'decimal:2',
            'auto_invoice_retainer' => 'boolean',
            'health_status' => ClientHealth::class,
            'health_computed_at' => 'datetime',
            'health_reasons' => 'array',
            'target_posts_per_week' => 'integer',
            'report_schedule' => ClientReportSchedule::class,
        ];
    }

    /**
     * Attributes `ClientHealthService`'s rules read — changing one re-materializes health.
     *
     * @var list<string>
     */
    public const HEALTH_INPUTS = ['status', 'owner_user_id', 'start_date', 'retainer_amount', 'billing_cycle', 'target_posts_per_week'];

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
     * Step M-4.1 — the client's default tax rate, applied to its retainer and to new fixed-amount
     * and campaign-fee lines.
     *
     * @return BelongsTo<TaxRate, $this>
     */
    public function defaultTaxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'default_tax_rate_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Step M-4.1 — the currency this client is billed in: its own `billing_currency` when set,
     * otherwise the organization's default. Invoices for the client default to it, and finalizing
     * a retainer invoice in any other currency is rejected (no conversion is performed).
     */
    public function billingCurrency(): string
    {
        return $this->billing_currency ?: $this->organization->default_currency;
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
     * Phase 18: the client's Instagram grid plans.
     *
     * @return HasMany<GridPlan, $this>
     */
    public function gridPlans(): HasMany
    {
        return $this->hasMany(GridPlan::class);
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

        $computed = app(ClientHealthService::class)->compute($this);

        return ['status' => $computed['status'], 'reason' => $computed['reason']];
    }

    /**
     * Step M-3.4 — health as `$viewer` may see it: the stored status (so lists sort and filter the
     * same for everyone) with the reasons they're allowed to read, heaviest first. Owner-only
     * reasons (overdue invoices) are dropped for everyone else; when one of those is the headline,
     * a non-Owner gets the next visible reason, or a plain "an Owner has the details" line.
     *
     * @return array{status: ClientHealth, reason: string, reasons: list<array{key: string, tone: string, weight: int, label: string}>}
     */
    public function healthFor(?User $viewer): array
    {
        $health = $this->health();
        $owner = $viewer !== null && ($viewer->isOwner() || $viewer->isSuperAdmin());
        $reasons = array_values(array_filter(
            $this->health_reasons ?? [],
            fn (array $reason) => $owner || ! ($reason['owner_only'] ?? false),
        ));
        $visible = array_values(array_filter($reasons, fn (array $reason) => $reason['tone'] !== ClientHealth::Green->value));

        $reason = $health['reason'];

        if (! $owner && collect($this->health_reasons ?? [])->contains(fn (array $hidden) => ($hidden['owner_only'] ?? false) && $hidden['label'] === $reason)) {
            $reason = $visible[0]['label'] ?? 'Needs attention — an Owner has the details.';
        }

        return [
            'status' => $health['status'],
            'reason' => $reason,
            'reasons' => array_map(fn (array $item) => [
                'key' => $item['key'],
                'tone' => $item['tone'],
                'weight' => $item['weight'],
                'label' => $item['label'],
            ], $reasons),
        ];
    }

    /**
     * @return HasMany<ClientHealthHistory, $this>
     */
    public function healthHistory(): HasMany
    {
        return $this->hasMany(ClientHealthHistory::class);
    }

    /**
     * Step M-3.3: monthly client reports, newest first.
     *
     * @return HasMany<ClientReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(ClientReport::class)->latest('period_start');
    }
}
