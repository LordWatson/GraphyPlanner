<?php

namespace App\Models;

use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Step MCP-1.2 — a bearer token for the remote MCP transport. It resolves an incoming request to
 * exactly one organization and a capability scope set, which `AuthenticateMcp` turns into the
 * per-request `McpContext` every tool/resource reads from. This is Graphy's own token concept
 * (Phase 13's `ApiToken`/Sanctum layer is not built yet — see the plan's convergence rule); if
 * Phase 13 lands, these two must become one token/org/scope concept, not two.
 *
 * The plain token is only ever the return value of `CreateMcpTokenAction`; at rest we keep only
 * its SHA-256 (`token_hash`), exactly like `PortalLoginToken`/`ReviewToken`. Not org-scoped as an
 * Eloquent global scope: lookup by hash happens pre-auth, before any org context exists, so it
 * must query across organizations by hash (the hash is the secret that gates it).
 *
 * @property int $id
 * @property int $org_id
 * @property int|null $user_id
 * @property string $name
 * @property string $token_hash
 * @property list<string> $scopes
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['org_id', 'user_id', 'name', 'token_hash', 'scopes', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live token for a raw bearer value, or null (unknown or revoked). Unscoped on purpose:
     * authentication runs before any org context, so the hash is matched across all organizations.
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
        return $this->revoked_at === null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
