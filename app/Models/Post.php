<?php

namespace App\Models;

use App\Enums\ApprovalMode;
use App\Enums\AssetType;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostRecurrence;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Jobs\RecomputeClientHealthJob;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Policies\PostPolicy;
use App\Services\ClientHashtagService;
use App\Services\Grids\GridOrderRiskService;
use App\Services\SharedPropsCache;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int|null $campaign_id
 * @property int|null $created_by
 * @property int|null $assignee_id
 * @property int|null $reviewer_id
 * @property Carbon|null $due_at
 * @property PostStatus $status
 * @property Carbon|null $last_reminded_at
 * @property string|null $publish_dispatch_id
 * @property ApprovalMode $approval_mode
 * @property string|null $master_caption
 * @property string|null $review_message
 * @property array<int, string>|null $hashtags
 * @property string|null $link_url
 * @property string|null $first_comment
 * @property int|null $cover_asset_id
 * @property int|null $thumb_offset_ms
 * @property array<string, mixed>|null $music
 * @property array<string, mixed>|null $location
 * @property array<string, string>|null $media_format
 * @property array<string, mixed>|null $checklist_snapshot
 * @property bool $is_recyclable
 * @property int|null $recycle_after_days
 * @property int|null $recycled_from_post_id
 * @property PostRecurrence|null $recurrence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'campaign_id', 'created_by', 'assignee_id', 'reviewer_id', 'due_at',
    'status', 'last_reminded_at', 'publish_dispatch_id', 'approval_mode',
    'master_caption', 'review_message', 'hashtags', 'music', 'location', 'media_format', 'checklist_snapshot',
    'link_url', 'first_comment', 'cover_asset_id', 'thumb_offset_ms',
    'is_recyclable', 'recycle_after_days', 'recycled_from_post_id', 'recurrence',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'approval_mode' => ApprovalMode::class,
            'hashtags' => 'array',
            'music' => 'array',
            'location' => 'array',
            'media_format' => 'array',
            'checklist_snapshot' => 'array',
            'due_at' => 'datetime',
            'last_reminded_at' => 'datetime',
            'is_recyclable' => 'boolean',
            'recurrence' => PostRecurrence::class,
        ];
    }

    /**
     * Step I-1.5: only posts whose client isn't archived. Working lists (Home, calendar,
     * dashboard, reminders) hide an archived client's posts; history (analytics) keeps them.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeForActiveClients(Builder $query): void
    {
        $query->whereIn('client_id', Client::query()->select('id'));
    }

    /**
     * Step I-2.3: the set-based form of `PostPolicy::reschedule`'s post rules (status not
     * locked, no published target) — callers still check the role and org.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeReschedulable(Builder $query): void
    {
        $query->whereNotIn('posts.status', PostPolicy::NON_RESCHEDULABLE_STATUSES)
            ->whereDoesntHave('targets', fn (Builder $target) => $target->where('status', PostTargetStatus::Published));
    }

    protected static function booted(): void
    {
        // Some users type hashtags with a leading "#" (e.g. "#launch"), others without
        // ("launch"). Normalize on save so the stored value — and therefore every place that
        // renders it with its own "#" prefix (review portal, publish adapters, etc.) — is
        // consistent regardless of how the tag was entered.
        static::saving(function (self $post) {
            if (! is_array($post->hashtags)) {
                return;
            }

            $post->hashtags = collect($post->hashtags)
                ->map(fn ($tag) => ltrim(trim((string) $tag), '#'))
                ->filter(fn ($tag) => $tag !== '')
                ->unique()
                ->values()
                ->all();
        });

        // Step I-2.6: the portal's cached "Approvals" badge counts this client's waiting posts.
        static::saved(function (self $post) {
            if ($post->wasChanged('status') || $post->wasRecentlyCreated) {
                SharedPropsCache::forgetPendingApprovals($post->client_id);
            }

            // Step 18.11: a grid post that fails or leaves the schedule can put the grid's order
            // at risk — re-check its grid once the change is committed.
            if ($post->wasChanged('status') && ! $post->wasRecentlyCreated) {
                DB::afterCommit(fn () => app(GridOrderRiskService::class)->checkForPost($post));
            }

            // Step M-3.4: failures, overdue approvals, cadence and calendar gaps feed health.
            if ($post->wasChanged('status')) {
                RecomputeClientHealthJob::dispatch($post->client_id);
            }
        });
        static::deleted(fn (self $post) => SharedPropsCache::forgetPendingApprovals($post->client_id));
        // Step 18.9: a deleted (or soft-deleted) post frees the grid tile it was made from, so
        // the tile reads "No post yet" again.
        static::deleted(fn (self $post) => GridTile::query()->where('post_id', $post->id)->update(['post_id' => null]));
        static::restored(fn (self $post) => SharedPropsCache::forgetPendingApprovals($post->client_id));

        // Step I-2.6: keep the client's hashtag vocabulary (`client_hashtags`) in step with every
        // write path — actions, AI, factories. `updated` still sees the pre-save original. Runs
        // inside the caller's transaction, if any.
        static::created(fn (self $post) => app(ClientHashtagService::class)->recordChange($post, null));
        static::updated(function (self $post) {
            if ($post->wasChanged('hashtags')) {
                $before = $post->getOriginal('hashtags');
                app(ClientHashtagService::class)->recordChange($post, is_array($before) ? $before : null);
            }
        });
        static::deleted(function (self $post) {
            // Force-deleting an already-trashed post was counted out when it was trashed.
            if (! $post->isForceDeleting() || $post->getOriginal('deleted_at') === null) {
                app(ClientHashtagService::class)->recordRemoval($post);
            }
        });
        static::restored(fn (self $post) => app(ClientHashtagService::class)->recordChange($post, null));
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
     * Step M-5.4: the person doing the work, and the internal reviewer.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return HasMany<PostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * Step M-5.8 — the post this one was recycled or recurred from (null for an original).
     *
     * @return BelongsTo<Post, $this>
     */
    public function recycledFrom(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'recycled_from_post_id');
    }

    /**
     * Step M-5.8 — posts recycled or recurred from this one. A recurring chain's "leaf" is the one
     * with no (non-trashed) child.
     *
     * @return HasMany<Post, $this>
     */
    public function recycledInto(): HasMany
    {
        return $this->hasMany(Post::class, 'recycled_from_post_id');
    }

    /**
     * Step M-5.3: the content history — one revision per content-changing save, newest last.
     *
     * @return HasMany<PostRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PostRevision::class);
    }

    /**
     * Step I-3.2 / M-2.3: the image a video publishes with as its cover/thumbnail, if any.
     *
     * @return BelongsTo<Asset, $this>
     */
    public function coverAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'cover_asset_id')->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * Step C-3.2: the campaign deliverable this post was started from, if any.
     *
     * @return HasOne<CampaignDeliverable, $this>
     */
    public function deliverable(): HasOne
    {
        return $this->hasOne(CampaignDeliverable::class);
    }

    /**
     * Step 18.9: the grid tile this post was made from, if any — the editor shows a "From grid"
     * chip and the post's checklist compares it with the approved look (`grid_match`).
     *
     * @return HasOne<GridTile, $this>
     */
    public function gridTile(): HasOne
    {
        return $this->hasOne(GridTile::class);
    }

    /**
     * @return HasMany<PostActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(PostActivityLog::class);
    }

    /**
     * @return HasMany<PostApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(PostApproval::class);
    }

    /**
     * Step M-6.3 — the required approvals for this post, built from the client's approver contacts
     * and approval policy when it enters `waiting_client`. Ordered for sequential policies.
     *
     * @return HasMany<ApprovalStep, $this>
     */
    public function approvalSteps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<PostComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /**
     * Step M-6.4 — a query for this post's open visual-feedback threads: root comments that are
     * pinned (image/video/caption) or carry a suggested edit, and haven't been resolved. These
     * block a post from being approved (an Owner can still override).
     *
     * @return HasMany<PostComment, $this>
     */
    public function unresolvedFeedback(): HasMany
    {
        return $this->comments()
            ->whereNull('parent_id')
            ->whereNull('resolved_at')
            ->where(fn ($query) => $query->whereNotNull('anchor_type')->orWhereNotNull('suggested_edit'));
    }

    public function hasUnresolvedFeedback(): bool
    {
        return $this->unresolvedFeedback()->exists();
    }

    /**
     * @return HasMany<ReviewToken, $this>
     */
    public function reviewTokens(): HasMany
    {
        return $this->hasMany(ReviewToken::class);
    }

    /**
     * Media attached to this post (Step 0.10 editor UI). Reuses assets already uploaded/linked to
     * the post's client rather than duplicating storage. The pivot carries each image's framing
     * (focus point + zoom) for fixed-ratio crops — see `mediaRatioFor()` and `PublishImageCropper`.
     *
     * @return BelongsToMany<Asset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'post_assets')
            ->withPivot(['crop_x', 'crop_y', 'crop_zoom', 'alt_text', 'sort_order'])
            // Step I-3.2 / M-2.3: carousel slides publish in this order.
            ->orderByPivot('sort_order')
            ->orderBy('post_assets.id');
    }

    /**
     * The image format this post uses on a channel (the editor's "Format" control).
     */
    public function mediaRatioFor(Platform $platform): MediaRatio
    {
        return MediaRatio::resolve($this->media_format, $platform);
    }

    /**
     * A `scheduled` post with at least one channel the vendor is already holding for later
     * (`SendScheduledPostToVendorJob`) — those must be cancelled there before it's unscheduled,
     * moved, edited or deleted.
     */
    public function isHeldByVendor(): bool
    {
        $this->loadMissing('targets');

        return $this->status === PostStatus::Scheduled
            && $this->targets->contains(fn (PostTarget $target) => $target->isVendorHeld());
    }

    /**
     * Step I-3.6 / M-2.5: a `publishing` post that hasn't actually gone out anywhere — every
     * channel is either held by the vendor for a later time or failed before reaching it — so it
     * can still be unscheduled, moved or edited (its held channels are cancelled first).
     */
    public function canBeRecalled(): bool
    {
        $this->loadMissing('targets');

        return $this->status === PostStatus::Publishing
            && $this->targets->contains(fn (PostTarget $target) => $target->isVendorHeld())
            && $this->targets->every(fn (PostTarget $target) => $target->isVendorHeld() || $target->status === PostTargetStatus::Failed);
    }

    /**
     * The attached items that actually go out, in order: a post with a video sends that one video
     * (the vendor's video endpoint takes a single file); otherwise every image, as a carousel.
     * Documents and other files never publish.
     *
     * @return Collection<int, Asset>
     */
    public function publishableMedia(): Collection
    {
        $this->loadMissing('assets');

        $video = $this->assets->first(fn (Asset $asset) => $asset->type === AssetType::Video);

        return $video !== null
            ? new Collection([$video])
            : $this->assets->filter(fn (Asset $asset) => $asset->type === AssetType::Image)->values();
    }

    /**
     * `image` / `video` per item that publishes (`publishableMedia()`) — what the capability
     * registry matches formats against (`PlatformCapabilities::resolveFormat()`).
     *
     * @return list<string>
     */
    public function mediaKinds(): array
    {
        return array_values($this->publishableMedia()
            ->map(fn (Asset $asset) => $asset->type === AssetType::Video ? 'video' : 'image')
            ->all());
    }

    /**
     * Whether any attached media was generated by the AI Image Studio (Step 16.13), so publish
     * adapters can set the platform's AI-content label where supported.
     */
    public function hasAiGeneratedMedia(): bool
    {
        return $this->assets->contains(fn (Asset $asset) => $asset->is_ai_generated);
    }

    /**
     * The distinct set of platforms targeted by this post's `PostTarget`s, used by the §6
     * checklist's "media present" rule (text-only-capable platforms don't require media).
     * Eager-loads `targets.socialAccount` itself since callers (AI captions, checklist, image
     * briefs) often hold a bare route-bound post and lazy loading is disabled outside production.
     *
     * @return array<int, Platform>
     */
    public function targetPlatforms(): array
    {
        $this->loadMissing('targets.socialAccount');

        return $this->targets
            ->map(fn (PostTarget $target) => $target->socialAccount?->platform)
            ->filter()
            ->unique(fn ($platform) => $platform->value)
            ->values()
            ->all();
    }
}
