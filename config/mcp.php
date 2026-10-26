<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP server (Phase MCP-0 — learning spike)
    |--------------------------------------------------------------------------
    |
    | Graphy exposes its data and actions as an MCP server so AI assistants can
    | drive the agency on the user's behalf (see .junie/plans/mcp-server.md).
    |
    | Phase MCP-0 is a local/stdio learning spike with NO authentication and NO
    | tenancy resolution yet: tools resolve to a single hardcoded "dev org".
    | Auth (OAuth 2.1), token/org/scope resolution, rate limits and audit
    | logging all arrive in Phase MCP-1 — nothing here is shippable.
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
    | Step MCP-1.2 added bearer-token authentication (AuthenticateMcp) on the POST
    | route, so the endpoint is now authenticated + strictly org-scoped. It still
    | ships OFF by default: capability-scope gating, rate limiting, audit logging and
    | the secrets-exposure guard (MCP-1.3–1.6) are not in place yet, so do not enable
    | it anywhere internet-reachable until the rest of MCP-1 lands. This flag stays as
    | the per-env master switch (and the MCP-7.2 kill-switch surface).
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
