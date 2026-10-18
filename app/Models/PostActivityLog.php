<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\PostActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $user_id
 * @property PostStatus|null $from_status
 * @property PostStatus $to_status
 * @property string|null $note
 * @property int|null $review_token_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['post_id', 'user_id', 'from_status', 'to_status', 'note', 'review_token_id'])]
class PostActivityLog extends Model
{
    /** @use HasFactory<PostActivityLogFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => PostStatus::class,
            'to_status' => PostStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The review-portal token minted when this entry moved the post to `waiting_client`
     * (Step I-1.6 — only the id is stored, never the plain token or link).
     *
     * @return BelongsTo<ReviewToken, $this>
     */
    public function reviewToken(): BelongsTo
    {
        return $this->belongsTo(ReviewToken::class);
    }
}
