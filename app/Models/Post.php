<?php

namespace App\Models;

use App\Enums\ApprovalMode;
use App\Enums\MediaRatio;
use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use App\Policies\PostPolicy;
use App\Services\ClientHashtagService;
use App\Services\SharedPropsCache;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int|null $campaign_id
 * @property int|null $created_by
 * @property PostStatus $status
 * @property string|null $publish_dispatch_id
 * @property ApprovalMode $approval_mode
 * @property string|null $master_caption
 * @property string|null $review_message
 * @property array<int, string>|null $hashtags
 * @property array<string, mixed>|null $music
 * @property array<string, mixed>|null $location
 * @property array<string, string>|null $media_format
 * @property array<string, mixed>|null $checklist_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'campaign_id', 'created_by', 'status', 'publish_dispatch_id', 'approval_mode',
    'master_caption', 'review_message', 'hashtags', 'music', 'location', 'media_format', 'checklist_snapshot',
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
        });
        static::deleted(fn (self $post) => SharedPropsCache::forgetPendingApprovals($post->client_id));
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
     * @return HasMany<PostTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
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
     * @return HasMany<PostComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class);
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
            ->withPivot(['crop_x', 'crop_y', 'crop_zoom']);
    }

    /**
     * The image format this post uses on a channel (the editor's "Format" control).
     */
    public function mediaRatioFor(Platform $platform): MediaRatio
    {
        return MediaRatio::resolve($this->media_format, $platform);
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
