<?php

namespace App\Support\Api;

use App\Models\McpToken;
use App\Support\Mcp\McpContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Resolving a bearer token to an organization, shared by the MCP transport and the public REST API.
 *
 * Both surfaces authenticate the same way, against the same tokens, for the same reason: the org
 * comes from the token and never from request input, so cross-tenant access is impossible by
 * construction and every reused Action and the `OrganizationScope` safety net behave exactly as
 * they do for a signed-in member.
 *
 * Shared rather than copied because the two middlewares would always have to change together — the
 * `last_used_at` stamp, the guard binding, and the "fail closed when no member resolves" rule are
 * one decision, not two. What differs between them is only the per-surface switch the caller checks
 * (`organizations.mcp_enabled` for MCP) and the shape of the refusal, so that stays with each.
 *
 * `McpToken` is the single token concept on purpose. Its own docblock records the rule: if an API
 * token layer ever landed, "these two must become one token/org/scope concept, not two". This is
 * that layer, so it reuses the model rather than inventing a second one. The class name is still
 * MCP-shaped and now undersells what it does — a rename is worth doing, but it is mechanical churn
 * that belongs in its own change, not buried in a feature.
 */
final class BearerTokenAuth
{
    /**
     * The live token for a raw bearer value, or null when it is missing, unknown or revoked.
     */
    public static function resolve(?string $bearer): ?McpToken
    {
        if ($bearer === null || $bearer === '') {
            return null;
        }

        return McpToken::findActive($bearer);
    }

    /**
     * Stamp the token as used and act as a member of its organization for the rest of the request.
     *
     * Returns the context so a caller can read scopes from it. A token whose organization has no
     * resolvable member leaves nobody bound, and the tenancy checks downstream then fail closed —
     * which is the safe direction.
     */
    public static function actAs(McpToken $token): McpContext
    {
        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        $context = new McpContext(
            organization: $token->organization,
            scopes: $token->scopes,
            user: $token->user,
            token: $token,
        );

        app()->instance(McpContext::class, $context);

        if (($actingUser = $context->actingUser()) !== null) {
            Auth::setUser($actingUser);
        }

        return $context;
    }

    /**
     * One place that logs a refused request, so a token or hash can never reach a log line.
     *
     * @param  array<string, mixed>  $context
     */
    public static function logRefusal(string $surface, string $reason, array $context = []): void
    {
        Log::warning("{$surface} request rejected", ['reason' => $reason] + $context);
    }
}
