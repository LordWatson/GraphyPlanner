<?php

use App\Mcp\Servers\GraphyServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP servers
|--------------------------------------------------------------------------
|
| Phase MCP-0 (learning spike): Graphy's MCP server is registered on a LOCAL
| (stdio) transport only — an Artisan command the MCP Inspector / Claude
| Desktop can launch. There is deliberately NO web (HTTP) transport and NO
| authentication yet; the server reads one hardcoded dev org. Phase MCP-1
| adds `Mcp::web('/mcp', ...)` behind OAuth before anything is reachable over
| the internet. See .junie/plans/mcp-server.md.
|
*/

Mcp::local('graphy', GraphyServer::class);
