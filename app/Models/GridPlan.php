<?php

namespace App\Models;

use App\Enums\GridPlanStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Services\SharedPropsCache;
use Database\Factories\GridPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Phase 18 — an Instagram grid plan: the look of a client's profile grid (tiles, order, media,
 * framing) planned and approved before anything goes out. The grid approves the look; each tile
 * becomes an ordinary `Post` whose own workflow approves the words (Step 18.9).
 *
 * `status` only covers the approval stages; production progress is derived from the linked posts
 * (`GridProgressService`). `version` is bumped on every layout change (Step 18.4) so stale writes
 * — a reorder from an old tab, a client deciding on a grid that changed — are refused.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int $social_account_id
 * @property int|null $campaign_id
 * @property string $name
 * @property GridPlanStatus $status
 * @property string|null $review_message
 * @property int $pinned_count
 * @property bool $include_live_feed
 * @property int $version
 * @property array{items: list<array<string, mixed>>, passed: bool}|null $checklist_snapshot
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $approved_layout_hash
 * @property Carbon|null $order_risk_notified_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'social_account_id', 'campaign_id', 'name', 'status', 'review_message',
    'pinned_count', 'include_live_feed', 'version', 'checklist_snapshot', 'approved_at', 'approved_by',
    'approved_layout_hash', 'order_risk_notified_at', 'created_by',
])]
class GridPlan extends Model
{
    /** @use HasFactory<GridPlanFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** Instagram pins at most three posts to the front of a profile grid. */
    public const MAX_PINNED = 3;

    /** Tiles per grid row. */
    public const COLUMNS = 3;

    protected static function booted(): void
    {
        // The portal's cached "Grids" counts (`SharedPropsCache::portalGrids`).
        static::saved(function (self $plan) {
            if ($plan->wasChanged('status') || $plan->wasRecentlyCreated) {
                SharedPropsCache::forgetPortalGrids($plan->client_id);
            }
        });
        static::deleted(fn (self $plan) => SharedPropsCache::forgetPortalGrids($plan->client_id));
        static::restored(fn (self $plan) => SharedPropsCache::forgetPortalGrids($plan->client_id));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GridPlanStatus::class,
            'pinned_count' => 'integer',
            'include_live_feed' => 'boolean',
            'version' => 'integer',
            'checklist_snapshot' => 'array',
            'approved_at' => 'datetime',
            'order_risk_notified_at' => 'datetime',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        // An archived (soft-deleted) client still owns its records (Step I-1.5).
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * The one Instagram account this plan lays out.
     *
     * @return BelongsTo<SocialAccount, $this>
     */
    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Tiles in display order (position 1 = top-left = newest).
     *
     * @return HasMany<GridTile, $this>
     */
    public function tiles(): HasMany
    {
        return $this->hasMany(GridTile::class)->orderBy('position');
    }

    /**
     * @return HasMany<GridPlanActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(GridPlanActivityLog::class);
    }

    /**
     * @return HasMany<GridComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(GridComment::class);
    }

    /**
     * @return HasMany<ReviewToken, $this>
     */
    public function reviewTokens(): HasMany
    {
        return $this->hasMany(ReviewToken::class);
    }
}
