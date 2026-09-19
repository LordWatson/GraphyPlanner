<?php

namespace App\Models;

use Database\Factories\PostCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $user_id
 * @property string $body
 * @property bool $internal_only
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['post_id', 'user_id', 'body', 'internal_only'])]
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
            'internal_only' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
