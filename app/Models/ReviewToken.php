<?php

namespace App\Models;

use Database\Factories\ReviewTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A token granting scoped, unauthenticated access to the client review portal (Step 0.13).
 * The plain token is never persisted — only its hash — and is only returned once, at creation
 * time, by `App\Actions\ReviewTokens\CreateReviewTokenAction`.
 *
 * @property int $id
 * @property string $token_hash
 * @property int|null $client_id
 * @property int|null $post_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token_hash', 'client_id', 'post_id', 'expires_at', 'revoked_at'])]
class ReviewToken extends Model
{
    /** @use HasFactory<ReviewTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Whether the token can currently be used to access the review portal.
     */
    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
