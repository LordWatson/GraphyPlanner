<?php

namespace App\Support\Mcp;

use App\Models\Organization;

/**
 * Step MCP-1.2 — resolves the organization a tool/resource should read from, for either transport:
 *
 *  - Remote HTTP: `AuthenticateMcp` has bound an `McpContext`, so the org is the authenticated
 *    token's org. This is real multi-tenancy — proven by the two-org isolation test.
 *  - Local/stdio + in-process tool tests: there is no bearer token (auth is HTTP-only), so there is
 *    no context; we fall back to the single hardcoded `McpDevOrg` the MCP-0 spike used.
 *
 * On the HTTP transport a tool is never reached without a context — `AuthenticateMcp` 401s first —
 * so the dev-org fallback can only ever be hit on the trusted local transport. This keeps the spike
 * tools working locally while making them genuinely org-scoped the moment a real token is present.
 */
class McpTenant
{
    public static function organization(): ?Organization
    {
        $context = McpContext::current();

        return $context !== null ? $context->organization : McpDevOrg::resolve();
    }
}
