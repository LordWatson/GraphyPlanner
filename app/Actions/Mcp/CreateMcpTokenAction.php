<?php

namespace App\Actions\Mcp;

use App\Models\McpToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step MCP-1.2 — mint a bearer token for an organization's MCP connection. The plain token is
 * generated here, hashed at rest, and returned exactly once (the "AI connections" settings page in
 * MCP-6.1 shows it to the Owner once, then only its hash lives on). New tokens default to the
 * read-only scope set; elevated scopes (write/schedule/ai) are an explicit opt-in (gated in MCP-1.3).
 *
 * Single write, so no transaction (CLAUDE.md #5). Logs the creation with org/user/token-id context
 * but never the plain token or its hash (CLAUDE.md #6).
 */
class CreateMcpTokenAction
{
    /**
     * @param  list<string>  $scopes
     * @return array{token: string, model: McpToken}
     */
    public function __invoke(Organization $organization, string $name, array $scopes = ['read'], ?User $user = null): array
    {
        $plainToken = Str::random(64);

        $token = McpToken::create([
            'org_id' => $organization->id,
            'user_id' => $user?->id,
            'name' => $name,
            'token_hash' => McpToken::hashToken($plainToken),
            'scopes' => array_values(array_unique($scopes)),
        ]);

        Log::info('MCP token created', [
            'org_id' => $organization->id,
            'user_id' => $user?->id,
            'mcp_token_id' => $token->id,
            'scopes' => $token->scopes,
        ]);

        return ['token' => $plainToken, 'model' => $token];
    }
}
