<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step MCP-1.1 — gate for the remote Streamable HTTP MCP transport (`POST /mcp`).
 *
 * The HTTP endpoint is registered unconditionally so it can be exercised in tests and local dev,
 * but it is OFF by default (`config('mcp.http.enabled')` / `MCP_HTTP_ENABLED`). While it is off —
 * which includes production — every verb on the route 404s, so the endpoint simply does not exist
 * for the outside world.
 *
 * This is a deliberately minimal guard: the HTTP transport ships BEFORE authentication (OAuth 2.1
 * lands in Step MCP-1.2). Until auth + per-token org/scope resolution exist, the endpoint must not
 * be reachable in production, so it stays flagged off. MCP-1.2 adds the auth middleware that
 * actually rejects unauthenticated callers; this flag remains as the per-env master on/off switch
 * (and becomes the per-org/master kill switch surface in MCP-7.2).
 */
class EnsureMcpHttpEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('mcp.http.enabled'), 404);

        return $next($request);
    }
}
