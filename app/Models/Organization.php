<?php

namespace App\Models;

use App\Enums\OrganizationType;
use App\Enums\Role;
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

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OrganizationType $type
 * @property Carbon|null $onboarding_completed_at
 * @property string $default_timezone
 * @property string $default_currency
 * @property string|null $billing_details
 * @property string|null $payment_instructions
 * @property string|null $tax_registration_number
 * @property string|null $tax_registration_label
 * @property int $invoice_payment_terms_days
 * @property string|null $upload_post_key
 * @property string|null $upload_post_webhook_secret
 * @property string|null $xai_key
 * @property int|null $ai_monthly_image_quota
 * @property array<string, bool>|null $campaign_features
 * @property Carbon|null $suspended_at
 * @property string|null $suspension_reason
 * @property int|null $suspended_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['name', 'slug', 'type', 'default_timezone', 'default_currency', 'billing_details', 'payment_instructions', 'tax_registration_number', 'tax_registration_label', 'invoice_payment_terms_days', 'upload_post_key', 'upload_post_webhook_secret', 'xai_key', 'ai_monthly_image_quota', 'campaign_features'])]
#[Hidden(['upload_post_key', 'upload_post_webhook_secret', 'xai_key'])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, SoftDeletes;

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
            'onboarding_completed_at' => 'datetime',
            'upload_post_key' => 'encrypted',
            'upload_post_webhook_secret' => 'encrypted',
            'xai_key' => 'encrypted',
            'invoice_payment_terms_days' => 'integer',
            'ai_monthly_image_quota' => 'integer',
            'suspended_at' => 'datetime',
            'campaign_features' => 'array',
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
}
