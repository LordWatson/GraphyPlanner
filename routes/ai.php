<?php

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
| It ships OFF because authentication lands in Step MCP-1.2 (OAuth 2.1 + per-token
| org/scope resolution). The transport is unauthenticated until then and must not
| be reachable in production. See .junie/plans/mcp-server.md.
|
*/

Mcp::local('graphy', GraphyServer::class);

Route::middleware(EnsureMcpHttpEnabled::class)->group(function (): void {
    Mcp::web(config('mcp.http.path', 'mcp'), GraphyServer::class);
});
