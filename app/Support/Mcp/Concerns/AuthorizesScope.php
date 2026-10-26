<?php

namespace App\Support\Mcp\Concerns;

use App\Enums\McpScope;
use App\Support\Mcp\McpContext;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Response;

/**
 * Step MCP-1.3 — capability-scope gating for MCP primitives. A tool declares the scope(s) it needs
 * (see `App\Mcp\Tools\McpTool`); this concern checks the authenticated token carries them *before*
 * the tool's work — and therefore before any Action — runs. An under-scoped call is rejected with a
 * clear MCP error naming the missing scope, never a stack trace.
 *
 * The scope is only *necessary*, not *sufficient*: the Action/policy the tool calls still enforces
 * tenancy and the status/checklist rules on top (CLAUDE.md #3/#4). Rejections are logged at
 * `warning` with org/token/tool context but never the token secret (CLAUDE.md #6).
 *
 * Transport note: on the local/stdio transport (and the in-process tool tests) there is no
 * `McpContext` — that is the *trusted* developer transport, so no scope is required there. On the
 * remote HTTP transport a tool is never reached without a context (`AuthenticateMcp` 401s first),
 * so this fallback only ever applies locally. This mirrors `McpTenant`'s dev-org fallback exactly.
 */
trait AuthorizesScope
{
    /**
     * An MCP error `Response` if the current connection's token is missing any of the required
     * scopes, or `null` when the call is authorized to proceed. All listed scopes are required
     * (AND semantics).
     */
    protected function denyUnlessScoped(McpScope ...$required): ?Response
    {
        $context = McpContext::current();

        // Local/stdio + in-process tests: trusted transport, no token, no scope restriction.
        if ($context === null) {
            return null;
        }

        foreach ($required as $scope) {
            if (! $context->hasScope($scope->value)) {
                Log::warning('MCP call rejected: missing capability scope', [
                    'org_id' => $context->organization->id,
                    'mcp_token_id' => $context->token?->id,
                    'tool' => $this->name(),
                    'required_scope' => $scope->value,
                ]);

                return Response::error(sprintf(
                    'This connection is not authorized for "%s". It needs the "%s" scope (%s), '
                    .'which the organization owner can grant to this token in Graphy settings.',
                    $this->name(),
                    $scope->value,
                    $scope->label(),
                ));
            }
        }

        return null;
    }
}
