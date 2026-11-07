<?php

namespace App\Http\Middleware;

use App\Enums\McpScope;
use App\Support\Api\BearerTokenAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public REST API request with the same bearer tokens as the MCP transport.
 *
 * One token concept, not two — `McpToken`'s own docblock sets that rule, and an agency that has
 * issued a token should not have to issue a second one to use the API with it.
 *
 * Differences from `AuthenticateMcp`, which is why this is its own middleware rather than a flag:
 *
 *  - **No `mcp_enabled` gate.** An organization may want the API without exposing itself to an AI
 *    assistant, or the reverse. Tying them together would make one switch mean two things.
 *  - **A JSON error body, not a bare 401.** An API client reads the body; an MCP client reads the
 *    `WWW-Authenticate` header.
 *  - **Scope required per route.** Every endpoint names the scope it needs, so a read-only token
 *    cannot reach a write endpoint even if one is added later without thinking about it.
 *
 * Usage: `->middleware('api.token:read')`.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $token = BearerTokenAuth::resolve($request->bearerToken());

        if ($token === null) {
            BearerTokenAuth::logRefusal('API', 'missing, unknown or revoked bearer token', ['ip' => $request->ip()]);

            return self::error(
                'unauthenticated',
                'Send a valid token as `Authorization: Bearer <token>`. Create one in Settings → Agent access.',
                401,
            );
        }

        // Required, not optional: a route that forgets to name a scope would otherwise accept the
        // weakest token in the system. Failing here makes that a visible error in development.
        if ($scope === null) {
            BearerTokenAuth::logRefusal('API', 'route declared no scope', ['path' => $request->path()]);

            return self::error('misconfigured', 'This endpoint is misconfigured.', 500);
        }

        if (! $token->hasScope($scope)) {
            BearerTokenAuth::logRefusal('API', 'token lacks the required scope', [
                'org_id' => $token->org_id,
                'token_id' => $token->id,
                'required' => $scope,
            ]);

            return self::error(
                'insufficient_scope',
                sprintf('This endpoint needs the "%s" scope. %s', $scope, self::describe($scope)),
                403,
            );
        }

        BearerTokenAuth::actAs($token);

        return $next($request);
    }

    /**
     * Every error from the API has the same shape, so a client can handle them generically.
     */
    private static function error(string $code, string $message, int $status): Response
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private static function describe(string $scope): string
    {
        return McpScope::tryFrom($scope)?->label() ?? '';
    }
}
