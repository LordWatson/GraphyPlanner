<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\PublishMode;
use App\Enums\Role;
use App\Support\Plans\Plan;
use App\Support\Plans\PlanCatalog;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OrganizationType $type
 * @property bool $is_design_partner
 * @property Carbon|null $onboarding_completed_at
 * @property bool $require_post_review
 * @property bool $insights_digest_opt_out
 * @property string $default_timezone
 * @property string $default_currency
 * @property int|null $approval_sla_hours
 * @property int|null $inbox_sla_hours
 * @property string|null $billing_details
 * @property string|null $payment_instructions
 * @property string|null $tax_registration_number
 * @property string|null $tax_registration_label
 * @property int $invoice_payment_terms_days
 * @property string|null $upload_post_key
 * @property string|null $upload_post_webhook_secret
 * @property PublishMode|null $publish_mode
 * @property string|null $upload_post_profile
 * @property string|null $xai_key
 * @property int|null $ai_monthly_image_quota
 * @property bool $ai_enabled
 * @property int|null $ai_monthly_token_budget
 * @property string|null $plan
 * @property bool|null $billing_enabled
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $trial_ends_at
 * @property int $bonus_ai_text_credits
 * @property Carbon|null $bonus_ai_credits_expires_at
 * @property bool $mcp_enabled
 * @property array<string, bool>|null $campaign_features
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property int|null $suspended_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'type', 'is_design_partner', 'require_post_review', 'insights_digest_opt_out', 'default_timezone', 'default_currency', 'approval_sla_hours', 'inbox_sla_hours', 'billing_details', 'payment_instructions', 'tax_registration_number', 'tax_registration_label', 'invoice_payment_terms_days', 'upload_post_key', 'upload_post_webhook_secret', 'publish_mode', 'upload_post_profile', 'xai_key', 'ai_monthly_image_quota', 'ai_enabled', 'ai_monthly_token_budget', 'mcp_enabled', 'campaign_features'])]
#[Hidden(['upload_post_key', 'upload_post_webhook_secret', 'xai_key'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory, SoftDeletes;

    /**
     * The frozen "Preview Studio" fixture org that powers `/preview/*` (Step 0.14). Super admins
     * can't suspend or delete it, and it's left out of platform analytics as demo data.
     */
    public const PREVIEW_SLUG = 'preview-studio';

    /**
     * Step B-1.1: mirrors the column default so an unsaved org (e.g. `factory()->make()`) already
     * reads as an agency rather than a null type.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'agency',
        'is_design_partner' => false,
        'require_post_review' => false,
        'insights_digest_opt_out' => false,
        'ai_enabled' => false,
        'mcp_enabled' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'is_design_partner' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'require_post_review' => 'boolean',
            'insights_digest_opt_out' => 'boolean',
            'upload_post_key' => 'encrypted',
            'upload_post_webhook_secret' => 'encrypted',
            'publish_mode' => PublishMode::class,
            'xai_key' => 'encrypted',
            'invoice_payment_terms_days' => 'integer',
            'approval_sla_hours' => 'integer',
            'inbox_sla_hours' => 'integer',
            'ai_monthly_image_quota' => 'integer',
            'ai_enabled' => 'boolean',
            'ai_monthly_token_budget' => 'integer',
            'mcp_enabled' => 'boolean',
            'suspended_at' => 'datetime',
            'campaign_features' => 'array',
            'billing_enabled' => 'boolean',
            'trial_ends_at' => 'datetime',
            'bonus_ai_text_credits' => 'integer',
            'bonus_ai_credits_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'org_id');
    }

    /**
     * The organization's earliest Owner — the account a super admin treats as the primary contact.
     *
     * @return HasOne<User, $this>
     */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class, 'org_id')
            ->where('role', Role::Owner)
            ->oldestOfMany();
    }

    /**
     * @return HasMany<Client, $this>
     */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'org_id');
    }

    /**
     * Step B-1.2 — a business workspace's single primary brand (an ordinary `Client` row with
     * `is_primary = true`). Null for an agency, which has no single brand. `CurrentWorkspace`
     * resolves and caches it per request.
     *
     * @return HasOne<Client, $this>
     */
    public function primaryBrand(): HasOne
    {
        return $this->hasOne(Client::class, 'org_id')->where('is_primary', true);
    }

    /**
     * Step M-4.1 — the organization's tax rates (VAT/TRN rates offered on invoices).
     *
     * @return HasMany<TaxRate, $this>
     */
    public function taxRates(): HasMany
    {
        return $this->hasMany(TaxRate::class, 'org_id');
    }

    /**
     * Whether the agency has a tax registration number. When it does, its invoices read
     * "Tax invoice" rather than "Invoice" (Step M-4.1).
     */
    public function hasTaxRegistration(): bool
    {
        return filled($this->tax_registration_number);
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'org_id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'org_id');
    }

    /**
     * @return HasMany<StaffInvitation, $this>
     */
    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class, 'org_id');
    }

    /**
     * The super admin who suspended this organization (Step 15.3).
     *
     * @return BelongsTo<User, $this>
     */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Step B-1.1 — a one-brand business/creator workspace (see `OrganizationType`).
     */
    public function isBusiness(): bool
    {
        return $this->type === OrganizationType::Business;
    }

    /**
     * Step B-1.1 — a multi-client agency studio (the default).
     */
    public function isAgency(): bool
    {
        return $this->type === OrganizationType::Agency;
    }

    /**
     * Step B-2.2 — whether this business workspace still needs the guided setup. True only for a
     * business org that hasn't finished or skipped onboarding; agencies never onboard this way.
     */
    public function needsOnboarding(): bool
    {
        return $this->isBusiness() && $this->onboarding_completed_at === null;
    }

    /**
     * Step B-8.2 — whether this workspace should receive the weekly Insights email digest: a
     * business org that has finished onboarding and hasn't opted out. Agencies never receive it.
     */
    public function wantsInsightsDigest(): bool
    {
        return $this->isBusiness()
            && $this->onboarding_completed_at !== null
            && ! $this->insights_digest_opt_out;
    }

    /**
     * Step B-1.1 — organizations of one workspace type (super-admin segmentation, scheduled work).
     *
     * @param  Builder<Organization>  $query
     */
    public function scopeOfType(Builder $query, OrganizationType $type): void
    {
        $query->where('type', $type->value);
    }

    public function isPreviewFixture(): bool
    {
        return $this->slug === self::PREVIEW_SLUG;
    }

    /**
     * Step M-8.1 — on the design-partner offer. Set by a super admin; drives the partner usage view
     * and (from M-10) the auto-applied design-partner coupon.
     */
    public function isDesignPartner(): bool
    {
        return $this->is_design_partner;
    }

    /**
     * Step M-8.1 — the (up to five) organizations on the design-partner offer.
     *
     * @param  Builder<Organization>  $query
     */
    public function scopeDesignPartners(Builder $query): void
    {
        $query->where('is_design_partner', true);
    }

    /**
     * Real customer organizations — everything except the preview fixture.
     *
     * @param  Builder<Organization>  $query
     */
    public function scopeExcludingPreview(Builder $query): void
    {
        $query->where('slug', '!=', self::PREVIEW_SLUG);
    }

    /**
     * @param  Builder<Organization>  $query
     */
    public function scopeSuspended(Builder $query): void
    {
        $query->whereNotNull('suspended_at');
    }

    /**
     * @param  Builder<Organization>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('suspended_at');
    }

    /**
     * Phase CG-2 (Step CG-2.6) — the super-admin audit trail of plan/credit/billing changes.
     *
     * @return HasMany<PlanAuditLog, $this>
     */
    public function planAuditLogs(): HasMany
    {
        return $this->hasMany(PlanAuditLog::class, 'org_id');
    }

    /**
     * Phase CG-2 (Step CG-2.1) — the org's effective plan key: its explicit `plan`, or the default
     * for its workspace type (agencies → unlimited, businesses → free).
     */
    public function effectivePlanKey(): string
    {
        $catalog = app(PlanCatalog::class);

        return $catalog->resolve($this->plan, $this->type)->key;
    }

    /**
     * Phase CG-2 (Step CG-2.1) — the resolved {@see Plan} value object for this org.
     */
    public function currentPlan(): Plan
    {
        return app(PlanCatalog::class)->resolve($this->plan, $this->type);
    }

    /**
     * Phase CG-2 (Step CG-2.6) — any unexpired super-admin AI credit grant, else 0.
     */
    public function bonusAiTextCredits(): int
    {
        if ($this->bonus_ai_text_credits <= 0) {
            return 0;
        }

        if ($this->bonus_ai_credits_expires_at !== null && $this->bonus_ai_credits_expires_at->isPast()) {
            return 0;
        }

        return (int) $this->bonus_ai_text_credits;
    }

    /**
     * Cashier stores the Stripe customer id on this model, so the Customer Portal / invoices read a
     * descriptive email. Prefer the Owner's email.
     */
    public function stripeEmail(): ?string
    {
        return $this->owner?->email;
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }
}
