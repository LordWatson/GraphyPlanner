<?php

namespace App\Models;

use App\Enums\ApprovalMode;
use App\Enums\PostStatus;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property int|null $campaign_id
 * @property int|null $created_by
 * @property PostStatus $status
 * @property ApprovalMode $approval_mode
 * @property string|null $master_caption
 * @property array<int, string>|null $hashtags
 * @property array<string, mixed>|null $music
 * @property array<string, mixed>|null $location
 * @property array<string, mixed>|null $checklist_snapshot
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'campaign_id', 'created_by', 'status', 'approval_mode',
    'master_caption', 'hashtags', 'music', 'location', 'checklist_snapshot',
])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

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
            'checklist_snapshot' => 'array',
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
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
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
     * the post's client rather than duplicating storage.
     *
     * @return BelongsToMany<Asset, $this>
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'post_assets');
    }

    /**
     * The distinct set of platforms targeted by this post's `PostTarget`s, used by the §6
     * checklist's "media present" rule (text-only-capable platforms don't require media).
     *
     * @return array<int, \App\Enums\Platform>
     */
    public function targetPlatforms(): array
    {
        return $this->targets
            ->map(fn (PostTarget $target) => $target->socialAccount?->platform)
            ->filter()
            ->unique(fn ($platform) => $platform->value)
            ->values()
            ->all();
    }
}
