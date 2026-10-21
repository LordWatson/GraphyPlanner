<?php

namespace App\Models;

use App\Actions\Clients\RecomputeClientHealthAction;
use App\Enums\ConnectionStatus;
use App\Enums\Platform;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\SocialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property Platform $platform
 * @property string $handle
 * @property string|null $display_name
 * @property string $timezone
 * @property string|null $language
 * @property string|null $country
 * @property string|null $default_location
 * @property array<int, array{day: string, start: string, end: string}>|null $posting_windows
 * @property array<string, mixed>|null $platform_settings
 * @property string|null $persona_override
 * @property ConnectionStatus $connection_status
 * @property string|null $provider
 * @property string|null $external_profile_id
 * @property string|null $external_account_id
 * @property string|null $meta_access_token
 * @property Carbon|null $meta_access_token_expires_at
 * @property string|null $meta_instagram_user_id
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_health_check_at
 * @property string|null $last_health_error
 * @property string|null $token_expiry_warning
 * @property Carbon|null $metrics_synced_at
 * @property string|null $metrics_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'org_id', 'client_id', 'platform', 'handle', 'display_name', 'timezone',
    'language', 'country', 'default_location', 'posting_windows', 'persona_override',
    'connection_status', 'provider', 'external_profile_id', 'external_account_id', 'connected_at',
    'meta_access_token', 'meta_access_token_expires_at', 'meta_instagram_user_id',
    'platform_settings', 'last_health_check_at', 'last_health_error', 'token_expiry_warning',
    'metrics_synced_at', 'metrics_error',
])]
// Step I-1.6: never serialized — a `toArray()`/Inertia prop of an account can't leak the Meta
// token or the vendor profile id.
#[Hidden(['meta_access_token', 'external_profile_id'])]
class SocialAccount extends Model
{
    /** @use HasFactory<SocialAccountFactory> */
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** Step I-3.9 / M-2.7: token expiry warning stages (`token_expiry_warning`). */
    public const TOKEN_EXPIRES_THIS_WEEK = '7d';

    public const TOKEN_EXPIRES_TOMORROW = '1d';

    public const TOKEN_EXPIRED = 'expired';

    /** Days before expiry the first warning goes out. */
    public const TOKEN_WARNING_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'connection_status' => ConnectionStatus::class,
            'posting_windows' => 'array',
            'platform_settings' => 'array',
            'last_health_check_at' => 'datetime',
            'connected_at' => 'datetime',
            // Step 1.9.2: a Meta user access token must never be stored/logged in plaintext —
            // Laravel's `encrypted` cast handles that transparently for both reads and writes.
            'meta_access_token' => 'encrypted',
            'meta_access_token_expires_at' => 'datetime',
            'metrics_synced_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Step I-2.5: an account's connection status feeds its client's stored health.
        $recompute = function (self $account): void {
            $client = Client::query()->withoutGlobalScopes()->find($account->client_id);

            if ($client !== null) {
                app(RecomputeClientHealthAction::class)($client);
            }
        };

        static::saved(function (self $account) use ($recompute) {
            // Step M-2.7: so does a token expiry warning stage.
            if ($account->wasChanged('connection_status') || $account->wasChanged('client_id') || $account->wasChanged('token_expiry_warning')
                || ($account->wasRecentlyCreated && ! $account->wasChanged())) {
                $recompute($account);
            }
        });
        static::deleted($recompute);
        static::restored($recompute);
    }

    /**
     * Step I-3.9 / M-2.7: where the stored Meta token stands — `expired`, `1d` (expires within a
     * day), `7d` (within a week) or null (fine, or no token).
     */
    public function tokenExpiryStage(): ?string
    {
        if (blank($this->meta_access_token) || $this->meta_access_token_expires_at === null) {
            return null;
        }

        return match (true) {
            $this->meta_access_token_expires_at->isPast() => self::TOKEN_EXPIRED,
            $this->meta_access_token_expires_at->lte(now()->addDay()) => self::TOKEN_EXPIRES_TOMORROW,
            $this->meta_access_token_expires_at->lte(now()->addDays(self::TOKEN_WARNING_DAYS)) => self::TOKEN_EXPIRES_THIS_WEEK,
            default => null,
        };
    }

    /**
     * "expires in 5 days", "expires tomorrow", "has expired" — for Home and health reasons.
     */
    public function tokenExpiryPhrase(): ?string
    {
        $expiresAt = $this->meta_access_token_expires_at;

        if ($this->tokenExpiryStage() === null || $expiresAt === null) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return 'has expired';
        }

        $days = (int) ceil(now()->diffInHours($expiresAt) / 24);

        return $days <= 1 ? 'expires within a day' : "expires in {$days} days";
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
     * @return HasMany<PostTarget, $this>
     */
    public function postTargets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    /**
     * Step M-3.1: one row per day (followers, reach…), newest last.
     *
     * @return HasMany<SocialAccountMetric, $this>
     */
    public function dailyMetrics(): HasMany
    {
        return $this->hasMany(SocialAccountMetric::class);
    }
}
