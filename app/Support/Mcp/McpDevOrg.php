<?php

namespace App\Support\Mcp;

use App\Models\Organization;

/**
 * Phase MCP-0 (learning spike) — resolves the single "dev org" the unauthenticated spike
 * tools/resources read from. This is a deliberate stand-in for real tenancy: Phase MCP-1
 * replaces it with token → org resolution (see .junie/plans/mcp-server.md). Nothing that
 * leans on this is shippable.
 *
 * Resolution order: the configured `mcp.spike.dev_org_id`, then the seeded `graphy-agency`
 * organization, then the lowest-id organization. Returns null only when no org exists.
 */
class McpDevOrg
{
    public static function resolve(): ?Organization
    {
        $configured = config('mcp.spike.dev_org_id');

        if ($configured !== null) {
            $org = Organization::query()->whereKey($configured)->first();

            if ($org instanceof Organization) {
                return $org;
            }
        }

        return Organization::query()->where('slug', 'graphy-agency')->first()
            ?? Organization::query()->orderBy('id')->first();
    }
}
