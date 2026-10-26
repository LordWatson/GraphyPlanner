<?php

namespace App\Actions\Mcp;

use App\Models\McpToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Step MCP-6.1 / MCP-7.2 — revoke an MCP bearer token. A soft revoke (sets `revoked_at`) keeps the
 * audit history intact while making the token unusable immediately: `McpToken::findActive()` only
 * returns tokens with a null `revoked_at`, so the next `/mcp` call with this token is rejected 401.
 * Idempotent (re-revoking is a no-op). Single write, so no transaction (CLAUDE.md #5); logs with
 * org/user/token-id context, never the token (CLAUDE.md #6).
 */
class RevokeMcpTokenAction
{
    public function __invoke(McpToken $token, ?User $user = null): McpToken
    {
        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();

            Log::info('MCP token revoked', [
                'org_id' => $token->org_id,
                'user_id' => $user?->id,
                'mcp_token_id' => $token->id,
            ]);
        }

        return $token;
    }
}
