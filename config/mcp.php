<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | Graphy exposes its data and actions as an MCP server so AI assistants can
    | drive the agency on the user's behalf (see .junie/plans/mcp-server.md).
    |
    | The backbone (Phase MCP-1) is complete: over HTTP the server is a
    | bearer-token-authenticated, strictly org-scoped resource server with
    | capability-scope gating, per-connection rate limiting, audit logging and
    | an automated secrets guard. Phases MCP-2–5 add the read tools, draft/edit
    | tools, elevated publish/schedule/connect tools, the AI tools and the
    | packaged prompt workflows; MCP-6/7 add the settings surface, the per-org
    | master switch and usage/audit. The local/stdio transport stays
    | unauthenticated for dev and resolves the single hardcoded "dev org" below.
    |
    */

    'spike' => [
        /*
         * The org the MCP-0 spike tools/resources read from while there is no
         * authentication. Resolved by McpDevOrg: this id when set, otherwise
         * the `graphy-agency` seeded org, otherwise the lowest-id org.
         *
         * NEVER relied on once Phase MCP-1 lands real token/org resolution.
         */
        'dev_org_id' => env('MCP_DEV_ORG_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote HTTP transport (Phase MCP-1.1)
    |--------------------------------------------------------------------------
    |
    | Graphy's MCP server is exposed over a remote Streamable HTTP transport at
    | `POST /{path}` (default `/mcp`), alongside the local/stdio command kept
    | for dev and tests. The route is always registered but guarded by
    | `EnsureMcpHttpEnabled`: while `enabled` is false — the default, including
    | production — every request to it 404s.
    |
    | This is the per-ENV master switch: the whole backbone (auth, scopes, rate
    | limiting, audit, secrets guard) is in place, so it is safe to enable, but it
    | stays off by default so a deployment opts in explicitly. The per-ORG master
    | switch (`organizations.mcp_enabled`, MCP-7.2) is separate and checked after
    | authentication, so an org can be turned off individually without this flag.
    |
    */

    'http' => [
        'enabled' => env('MCP_HTTP_ENABLED', false),
        'path' => env('MCP_HTTP_PATH', 'mcp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting / abuse guard (Phase MCP-1.4)
    |--------------------------------------------------------------------------
    |
    | Every tool call on the remote HTTP transport is counted per connection
    | (per token, falling back to the org) against a rolling one-minute window.
    | Once the budget is spent the call is refused with a clean MCP error — not
    | a 500 — so a runaway assistant loop can't hammer Graphy or the vendor
    | APIs. Set `per_minute` to 0 to disable limiting. The trusted local/stdio
    | transport is never throttled.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('MCP_RATE_LIMIT_PER_MINUTE', 60),
    ],

];
