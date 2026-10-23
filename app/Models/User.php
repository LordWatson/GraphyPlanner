<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $org_id
 * @property Role|null $role
 * @property int|null $client_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'org_id', 'role', 'client_id'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
/**
 * Step 15.2.3: `MustVerifyEmail` makes the `verified` route middleware enforce Fortify's email
 * verification flow — needed now that anyone can sign up through `/register-organization`.
 * Invitation accepts mark the address verified themselves (the link proved inbox ownership).
 */
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The frozen "Preview Studio" owner seeded by `PreviewSeeder` (Step 0.14). Only ever
     * authenticated per-request on `/preview/*` (Step I-1.1).
     */
    public const PREVIEW_EMAIL = 'preview@graphy.test';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
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
     * The client this user is scoped to, when acting as a Client reviewer.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScope(OrganizationScope::class);
    }

    /**
     * @return HasMany<StaffActivityLog, $this>
     */
    public function staffActivityLogs(): HasMany
    {
        return $this->hasMany(StaffActivityLog::class, 'user_id');
    }

    /**
     * Step B-7.4 — the user's browser push subscriptions (one per device/browser).
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function isOwner(): bool
    {
        return $this->role === Role::Owner;
    }

    public function hasRole(Role $role): bool
    {
        return $this->role === $role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isPreviewUser(): bool
    {
        return $this->email === self::PREVIEW_EMAIL;
    }
}
