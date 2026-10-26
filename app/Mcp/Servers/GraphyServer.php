<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\BrandBrainResource;
use App\Mcp\Tools\ListClientsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

/**
 * Phase MCP-0 (learning spike) — Graphy's MCP server: the single entry point that advertises
 * Graphy's tools, resources and prompts to AI assistants. See .junie/plans/mcp-server.md.
 *
 * Security: NOT YET SCOPED — dev only. This server runs on a local/stdio transport with no
 * authentication; its tools/resources read from one hardcoded dev org (see McpDevOrg). Phase
 * MCP-1 adds remote HTTP transport, OAuth 2.1, per-token org/scope resolution, rate limiting
 * and audit logging before any of this is exposed to customers.
 */
#[Name('Graphy')]
#[Version('0.0.1')]
#[Instructions(
    'Graphy runs a social-media agency: clients (brands), their brand brains (voice, audience, '
    .'offer, visual and content guidelines), posts, campaigns and a content calendar. Use the '
    .'tools to read agency data and the resources to load a client\'s brand context. This is an '
    .'early development build exposing read-only data for a single development organization.'
)]
class GraphyServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListClientsTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        BrandBrainResource::class,
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
