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

];
