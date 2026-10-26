<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use App\Support\Mcp\McpContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step MCP-1.2 — authenticates a remote (HTTP) MCP request as an OAuth 2.1 resource server using a
 * bearer token. It runs only on the `POST /mcp` route (after `EnsureMcpHttpEnabled`), so the GET/
 * DELETE method-not-allowed stubs are untouched.
 *
 * A missing or invalid/revoked bearer token is rejected with a bare HTTP 401 — the package's
 * `AddWwwAuthenticateHeader` middleware (also on this route) then attaches the
 * `WWW-Authenticate: Bearer` challenge, so the assistant knows it must authenticate. A 401 is
 * returned before the JSON-RPC dispatcher runs, so no tool/resource ever executes unauthenticated.
 *
 * On success it resolves the token to exactly one organization + its scope set, records
 * `last_used_at`, and binds the per-request `McpContext` so every tool/resource reads tenancy and
 * capability from it. The token hash/secret is never logged (CLAUDE.md #6). Cross-org access is
 * impossible by construction: the org comes from the token, not from any model input.
 *
 * NOTE: this is the manually-issued-bearer-token flow (the plan's Appendix B "simpler" option and
 * the package's documented Sanctum/custom-middleware path). Full OAuth authorization-server
 * behaviour — dynamic client registration, Passport — is deferred; the package's Passport path only
 * issues a single `mcp:use` scope anyway, so Graphy's capability scopes live in-app regardless.
 */
class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return $this->unauthenticated('missing bearer token');
        }

        $token = McpToken::findActive($bearer);

        if ($token === null) {
            return $this->unauthenticated('unknown or revoked token');
        }

        // Step MCP-7.2: the per-org master switch. Even with a valid token, an org that has switched
        // MCP off rejects every call immediately (a 403, distinct from the 401 for a bad token).
        if ($token->organization->mcp_enabled !== true) {
            Log::warning('MCP request rejected: org switch off', ['org_id' => $token->org_id, 'token_id' => $token->id]);

            return response()->json(['error' => 'mcp_disabled'], 403);
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $context = new McpContext(
            organization: $token->organization,
            scopes: $token->scopes,
            user: $token->user,
            token: $token,
        );

        app()->instance(McpContext::class, $context);

        // Bind the acting member onto the auth guard for the request so every reused Action/query and
        // the `OrganizationScope` safety net scope to the token's org exactly as they do for the web
        // UI (MCP-2). The member is always the token's own org, so this can never widen tenancy; it
        // only enables reuse. A token for an org with no resolvable Owner leaves no member bound — the
        // tools' explicit org filter / `actAsOrganizationMember()` null-guard then fails closed.
        if (($actingUser = $context->actingUser()) !== null) {
            Auth::setUser($actingUser);
        }

        return $next($request);
    }

    private function unauthenticated(string $reason): Response
    {
        Log::warning('MCP request rejected: unauthenticated', ['reason' => $reason]);

        // A bare 401; AddWwwAuthenticateHeader adds the Bearer challenge. No JSON-RPC body — this is
        // a transport-level auth failure, which MCP clients handle via the WWW-Authenticate header.
        return response()->json(['error' => 'unauthenticated'], 401);
    }
}
