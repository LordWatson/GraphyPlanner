<?php

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Campaigns enhancement C-5.4 — a read-only preview link to a campaign's approved-onward posts.
 * Only the SHA-256 of the token is stored; the plain token exists once, in the creator's response.
 *
 * @property int $id
 * @property int $campaign_id
 * @property int|null $created_by
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_viewed_at
 * @property int $view_count
 * @property Carbon|null $created_at
 */
#[Fillable(['campaign_id', 'created_by', 'token_hash', 'expires_at', 'revoked_at', 'last_viewed_at', 'view_count'])]
class CampaignShareLink extends Model
{
    public const DEFAULT_DAYS = 14;

    public const MAX_DAYS = 90;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'view_count' => 'integer',
        ];
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

    public function isValid(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
