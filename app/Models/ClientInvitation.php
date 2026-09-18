<?php

namespace App\Models;

use Database\Factories\ClientInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation for a named contact at a client to join the Step 6.2 client portal. The plain
 * token is never persisted — only its hash — and is only returned once, at creation time, by
 * `App\Actions\ClientInvitations\CreateClientInvitationAction`.
 *
 * @property int $id
 * @property int $client_id
 * @property string $email
 * @property int $invited_by_user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['client_id', 'email', 'invited_by_user_id', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'])]
class ClientInvitation extends Model
{
    /** @use HasFactory<ClientInvitationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Whether the invitation is still pending (unaccepted, unrevoked, unexpired) — used both to
     * list "pending" invitations on the client page and to validate the accept-invite token in
     * Step 6.2.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
