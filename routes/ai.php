<?php

use App\Http\Middleware\AuthenticateMcp;
use App\Http\Middleware\EnsureMcpHttpEnabled;
use App\Mcp\Servers\GraphyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP servers
|--------------------------------------------------------------------------
|
| LOCAL (stdio) transport — an Artisan command (`php artisan mcp:start graphy`)
| the MCP Inspector / Claude Desktop can launch. Kept for local dev and tests.
|
| REMOTE (Streamable HTTP) transport (Phase MCP-1.1) — the server is also mounted
| at `POST /mcp` (path configurable via `mcp.http.path`) so internet-connected
| assistants can reach it. It is wrapped in `EnsureMcpHttpEnabled`, so while
| `mcp.http.enabled` is false — the default, including production — every verb on
| the route 404s.
|
| AUTH (Phase MCP-1.2) — the POST route (the only one that runs the server) carries
| `AuthenticateMcp`, so an unauthenticated/invalid-bearer request is rejected 401
| before any tool/resource runs and the request resolves to exactly one org + scope
| set via `McpContext`. The GET/DELETE 405 stubs stay unauthenticated (they carry no
| data). See .junie/plans/mcp-server.md.
|
*/

Mcp::local('graphy', GraphyServer::class);

Route::middleware(EnsureMcpHttpEnabled::class)->group(function (): void {
    Mcp::web(config('mcp.http.path', 'mcp'), GraphyServer::class)
        ->middleware(AuthenticateMcp::class);
});
