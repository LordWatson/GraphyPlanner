<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\StaffInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation for a named person to join an organization's staff (Owner/Strategist/Designer/
 * Viewer). The plain token is never persisted — only its hash — and is only returned once, at
 * creation time, by `App\Actions\StaffInvitations\CreateStaffInvitationAction`.
 *
 * @property int $id
 * @property int $org_id
 * @property string $email
 * @property Role $role
 * @property int $invited_by_user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'email', 'role', 'invited_by_user_id', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'])]
class StaffInvitation extends Model
{
    /** @use HasFactory<StaffInvitationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Whether the invitation is still pending (unaccepted, unrevoked, unexpired) — used both to
     * list "pending" invitations on the staff settings page and to validate the accept-invite
     * token.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
