<?php

namespace App\Mcp\Resources;

use App\Models\Client;
use App\Support\Mcp\McpDevOrg;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * Phase MCP-0.3 (learning spike) — exposes a client's Brand Brain (voice, audience, offer,
 * visual, music/hashtag policy, content pillars) as read-only markdown context, reusing the
 * canonical BrandBrain::toGrokContext() shape the AI module already consumes.
 *
 * Spike: no arguments, so it serves the dev org's first client that has a brand brain. Phase
 * MCP-1/MCP-2 scope this per token and expose it per client via a resource template.
 *
 * Security: NOT YET SCOPED — dev only. See .junie/plans/mcp-server.md.
 */
#[Name('brand_brain')]
#[Title('Client brand brain')]
#[Description('The brand brain (voice, audience, offer, visual style, music/hashtag policy and content pillars) for the development organization\'s first client that has one, as markdown context.')]
#[Uri('graphy://brand-brain')]
#[MimeType('text/markdown')]
class BrandBrainResource extends Resource
{
    /**
     * Handle the resource request.
     */
    public function handle(Request $request): Response
    {
        $org = McpDevOrg::resolve();

        if ($org === null) {
            return Response::error('No development organization exists yet. Seed an organization first.');
        }

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->whereHas('brandBrain')
            ->with('brandBrain')
            ->orderBy('id')
            ->first();

        if ($client?->brandBrain === null) {
            return Response::error('No client in the development organization has a brand brain yet.');
        }

        return Response::text($client->brandBrain->toGrokContext()['markdown']);
    }
}
