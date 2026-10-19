<?php

namespace App\Models;

use App\Enums\Platform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step M-2.8 — a client self-connect link. The plain token is never stored (only its SHA-256),
 * and is shown/emailed once by `CreateConnectInviteAction`. It only ever reaches its own client's
 * accounts, optionally narrowed to specific account slots or platforms.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $token_hash
 * @property string|null $email
 * @property list<int>|null $allowed_social_account_ids
 * @property list<string>|null $platforms
 * @property list<int>|null $connected_account_ids
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $used_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'token_hash', 'email', 'allowed_social_account_ids', 'platforms',
    'connected_account_ids', 'expires_at', 'revoked_at', 'used_at', 'created_by',
])]
class ConnectInvite extends Model
{
    use BelongsToOrganization;

    public const DEFAULT_TTL_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed_social_account_ids' => 'array',
            'platforms' => 'array',
            'connected_account_ids' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live invite for a raw token from a public URL, or null (unknown, expired or revoked).
     * Unscoped: the visitor isn't signed in.
     */
    public static function findActive(string $token): ?self
    {
        /** @var self|null $invite */
        $invite = self::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('token_hash', self::hashToken($token))
            ->first();

        return $invite?->isActive() ? $invite : null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * @param  Builder<ConnectInvite>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * The account slots this invite may connect: its client's accounts, narrowed to the chosen
     * slots and/or platforms. Never another client's.
     *
     * @return Collection<int, SocialAccount>
     */
    public function connectableAccounts(): Collection
    {
        return SocialAccount::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('org_id', $this->org_id)
            ->where('client_id', $this->client_id)
            ->when($this->allowed_social_account_ids, fn (Builder $query, array $ids) => $query->whereIn('id', $ids))
            ->when($this->platforms, fn (Builder $query, array $platforms) => $query->whereIn('platform', $platforms))
            ->orderBy('platform')
            ->orderBy('handle')
            ->get();
    }

    public function allows(SocialAccount $account): bool
    {
        return $account->org_id === $this->org_id
            && $account->client_id === $this->client_id
            && ($this->allowed_social_account_ids === null || $this->allowed_social_account_ids === [] || in_array($account->id, $this->allowed_social_account_ids, true))
            && ($this->platforms === null || $this->platforms === [] || in_array($account->platform->value, $this->platforms, true));
    }

    /**
     * Human list of what the invite covers, for the staff list ("Instagram, TikTok" / "All
     * accounts").
     */
    public function scopeLabel(): string
    {
        if ($this->platforms) {
            return collect($this->platforms)->map(fn (string $value) => Platform::tryFrom($value)?->label() ?? $value)->implode(', ');
        }

        return $this->allowed_social_account_ids ? count($this->allowed_social_account_ids).' selected accounts' : 'All accounts';
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed()->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
