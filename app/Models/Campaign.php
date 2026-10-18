<?php

namespace App\Models;

use App\Enums\CampaignBillingType;
use App\Enums\CampaignColour;
use App\Enums\CampaignObjective;
use App\Enums\CampaignStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\CampaignFactory;
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
 * @property int $client_id
 * @property int|null $owner_id
 * @property string $name
 * @property string|null $code
 * @property CampaignStatus $status
 * @property CampaignObjective|null $objective
 * @property CampaignColour|null $colour
 * @property list<string>|null $platforms
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $goal
 * @property string|null $notes
 * @property list<string>|null $hashtags
 * @property string|null $default_link_url
 * @property string|null $utm_campaign
 * @property CampaignBillingType|null $billing_type
 * @property string|null $fee_amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'owner_id', 'name', 'code', 'status', 'objective', 'colour', 'platforms',
    'start_date', 'end_date', 'goal', 'notes', 'hashtags', 'default_link_url', 'utm_campaign',
    'billing_type', 'fee_amount',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'objective' => CampaignObjective::class,
            'colour' => CampaignColour::class,
            'platforms' => 'array',
            'hashtags' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'billing_type' => CampaignBillingType::class,
            'fee_amount' => 'decimal:2',
        ];
    }

    /**
     * Step C-0.3: append one row to the campaign's audit trail.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function recordActivity(string $event, ?User $user, ?string $from = null, ?string $to = null, ?array $meta = null): CampaignActivityLog
    {
        return $this->activityLogs()->create([
            'user_id' => $user?->id,
            'event' => $event,
            'from' => $from,
            'to' => $to,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        // Step I-1.5: an archived (soft-deleted) client still owns its records.
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Step C-0.2: the accountable strategist.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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
     * @return HasMany<CampaignActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(CampaignActivityLog::class);
    }

    /**
     * Step C-2.1.
     *
     * @return HasOne<CampaignBrief, $this>
     */
    public function brief(): HasOne
    {
        return $this->hasOne(CampaignBrief::class);
    }

    /**
     * Step C-3.1, in plan order.
     *
     * @return HasMany<CampaignPhase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(CampaignPhase::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Step C-3.2.
     *
     * @return HasMany<CampaignDeliverable, $this>
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(CampaignDeliverable::class);
    }

    /**
     * Step C-7.1, in display order.
     *
     * @return HasMany<CampaignKpi, $this>
     */
    public function kpis(): HasMany
    {
        return $this->hasMany(CampaignKpi::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * Step C-7.3.
     *
     * @return HasOne<CampaignReport, $this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(CampaignReport::class);
    }

    /**
     * Step C-5.3.
     *
     * @return HasMany<CampaignComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(CampaignComment::class);
    }

    /**
     * C-5.4: read-only preview links for stakeholders without portal access.
     *
     * @return HasMany<CampaignShareLink, $this>
     */
    public function shareLinks(): HasMany
    {
        return $this->hasMany(CampaignShareLink::class);
    }

    /**
     * Step C-8.2: invoice lines that bill this campaign.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
