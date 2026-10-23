<?php

namespace App\Models;

use Database\Factories\PortalLoginTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-6.2 — a single-use, expiring passwordless login link for a client reviewer. The plain
 * token is never persisted (only its SHA-256) and is minted once by `CreatePortalLoginTokenAction`.
 * Using it signs the reviewer into the portal and consumes the token (`used_at`).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property int|null $post_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'token_hash', 'post_id', 'expires_at', 'used_at'])]
class PortalLoginToken extends Model
{
    /** @use HasFactory<PortalLoginTokenFactory> */
    use HasFactory;

    public const DEFAULT_TTL_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live token for a raw token from the magic-link URL, or null (unknown, expired or already
     * used). Unscoped: the visitor isn't signed in yet.
     */
    public static function findActive(string $token): ?self
    {
        $model = self::query()
            ->where('token_hash', self::hashToken($token))
            ->first();

        return $model?->isActive() === true ? $model : null;
    }

    public function isActive(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
