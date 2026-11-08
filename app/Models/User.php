<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Scopes\ClientAccessScope;
use App\Models\Scopes\OrganizationScope;
use App\Support\WhatsApp\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 * @property array<string, array<string, bool>>|null $notification_preferences
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $phone
 * @property Carbon|null $phone_verified_at
 * @property string|null $phone_verification_code
 * @property Carbon|null $phone_verification_sent_at
 * @property int $phone_verification_attempts
 * @property Carbon|null $last_active_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'org_id', 'role', 'client_id', 'notification_preferences'])]
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
            'phone_verified_at' => 'datetime',
            'phone_verification_sent_at' => 'datetime',
            'phone_verification_attempts' => 'integer',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'role' => Role::class,
            'notification_preferences' => 'array',
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
     * Step M-9.2 — inbox threads assigned to this staff member.
     *
     * @return HasMany<InboxConversation, $this>
     */
    public function assignedConversations(): HasMany
    {
        return $this->hasMany(InboxConversation::class, 'assigned_to');
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
     * The clients this staff member has been put on, when the organization restricts access that
     * way. Distinct from `client()` above, which is the one client a *client reviewer* belongs to.
     *
     * @return BelongsToMany<Client, $this>
     */
    public function assignedClients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class)
            ->withoutGlobalScope(OrganizationScope::class)
            // And its own access scope: this relation is what the scope *reads* to decide what may
            // be read, so leaving it on sends `accessibleClientIds()` into infinite recursion.
            ->withoutGlobalScope(ClientAccessScope::class);
    }

    /**
     * The client ids this user may see, or **null for "all of them"**.
     *
     * Null rather than an exhaustive list on purpose: it is the difference between "no restriction
     * applies" and "assigned to nothing", and conflating the two would either open everything up or
     * lock an Owner out of their own roster. Callers must treat null as unrestricted.
     *
     * Unrestricted when: the organization has not turned the restriction on, the user is an Owner
     * (somebody has to see the whole roster), or the user is a platform super admin. A client
     * reviewer is unaffected — their access is already pinned to `client_id` by the portal guard.
     *
     * @return list<int>|null
     */
    public function accessibleClientIds(): ?array
    {
        if ($this->isSuperAdmin() || $this->isOwner() || $this->hasRole(Role::ClientReviewer)) {
            return null;
        }

        if ($this->relationLoaded('organization')) {
            $restricted = (bool) $this->organization?->restrict_staff_to_assigned_clients;
        } else {
            $restricted = (bool) Organization::query()
                ->whereKey($this->org_id)
                ->value('restrict_staff_to_assigned_clients');
        }

        if (! $restricted) {
            return null;
        }

        return array_values($this->assignedClients()->pluck('clients.id')->map(fn ($id) => (int) $id)->all());
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

    /**
     * Step W-0.4 — where the `whatsapp` notification channel sends, or null if nowhere.
     *
     * Null unless the number has been verified (Step W-1.1): an unconfirmed number may belong to
     * somebody else entirely, and the one thing WhatsApp must never do is message a stranger. A
     * number saved without a country code is unroutable for the same reason — see `PhoneNumber`.
     */
    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->phone_verified_at === null ? null : PhoneNumber::e164($this->phone);
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
