<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Scopes\OrganizationScope;
use Database\Factories\McpUploadSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * MCP media — a short-lived browser link that lets a human finish media uploads for the MCP assistant
 * (`request_media_upload`). One session is scoped to a single client and a fixed set of its posts, and
 * stays open (not single-use) so the user can drop many files across several posts in one sitting
 * ("create 3 posts, I'll upload media for each"). The plain token is never stored (only its SHA-256)
 * and is placed in the URL once by `CreateMcpUploadSessionAction`. Mirrors `ConnectInvite`.
 *
 * @property int $id
 * @property int $org_id
 * @property int $client_id
 * @property string $token_hash
 * @property list<int>|null $post_ids
 * @property list<int>|null $uploaded_asset_ids
 * @property Carbon $expires_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'org_id', 'client_id', 'token_hash', 'post_ids', 'uploaded_asset_ids', 'expires_at', 'created_by',
])]
class McpUploadSession extends Model
{
    /** @use HasFactory<McpUploadSessionFactory> */
    use BelongsToOrganization, HasFactory;

    /**
     * A batch handoff takes longer than a single file (the user uploads media for several posts), but
     * it's still an interactive session, not a days-long invite.
     */
    public const DEFAULT_TTL_MINUTES = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'post_ids' => 'array',
            'uploaded_asset_ids' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live session for a raw token from a public URL, or null (unknown or expired). Unscoped: the
     * visitor isn't signed in.
     */
    public static function findActive(string $token): ?self
    {
        /** @var self|null $session */
        $session = self::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('token_hash', self::hashToken($token))
            ->first();

        return $session?->isActive() ? $session : null;
    }

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }

    /**
     * May an upload in this session attach to the given post? Only the client's posts the session was
     * issued for.
     */
    public function allowsPost(int $postId): bool
    {
        return in_array($postId, $this->post_ids ?? [], true);
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
