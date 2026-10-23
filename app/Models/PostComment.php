<?php

namespace App\Models;

use App\Enums\CommentAnchorType;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\PostCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string $body
 * @property CommentAnchorType|null $anchor_type
 * @property array<string, mixed>|null $anchor
 * @property string|null $suggested_edit
 * @property Carbon|null $suggestion_resolved_at
 * @property bool $internal_only
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PostComment|null $parent
 * @property-read User|null $resolvedBy
 */
#[Fillable([
    'post_id', 'parent_id', 'user_id', 'body', 'anchor_type', 'anchor',
    'suggested_edit', 'suggestion_resolved_at', 'internal_only', 'resolved_at', 'resolved_by_user_id',
])]
class PostComment extends Model
{
    /** @use HasFactory<PostCommentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anchor_type' => CommentAnchorType::class,
            'anchor' => 'array',
            'internal_only' => 'boolean',
            'resolved_at' => 'datetime',
            'suggestion_resolved_at' => 'datetime',
        ];
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /**
     * Step M-6.4 — a thread root is "feedback" (so it gates approval until resolved) when it's
     * pinned to the media/caption or carries a suggested edit. A plain comment is just a comment.
     */
    public function isFeedback(): bool
    {
        return $this->parent_id === null
            && ($this->anchor_type !== null || $this->suggested_edit !== null);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<PostComment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'parent_id');
    }

    /**
     * @return HasMany<PostComment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(PostComment::class, 'parent_id')->oldest('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /**
     * Users `@`-mentioned in this comment's body (org or client-portal users, resolved by
     * `CreatePostCommentAction` from the `@[Name](id)` tokens parsed out of `body`).
     *
     * @return BelongsToMany<User, $this>
     */
    public function mentionedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'post_comment_mentions')->withTimestamps();
    }
}
