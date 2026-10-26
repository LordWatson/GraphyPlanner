<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-0.2 → MCP-1.2 — a read-only tool: list the org's clients as a small structured list
 * (id, name, status). No arguments. Tenancy comes from `McpTenant`: the authenticated token's org
 * on the remote HTTP transport (real multi-tenancy), or the hardcoded dev org on the local/stdio
 * spike. MCP-2 reuses ListClientsAction's filters and adds scope/policy gating.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('list_clients')]
#[Title('List clients')]
#[Description('Lists the development organization\'s clients (brands) as a structured list of id, name and status. Read-only; takes no arguments.')]
#[IsReadOnly]
class ListClientsTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::error('No organization is available for this connection.');
        }

        // No signed-in member means the global OrganizationScope is a no-op, so the explicit
        // org_id filter is what keeps this to exactly the resolved token's (or dev) org.
        $clients = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (Client $client): array => [
                'id' => $client->id,
                'name' => $client->name,
                'status' => $client->status->value,
            ])
            ->all();

        return Response::structured([
            'organization' => $org->name,
            'count' => count($clients),
            'clients' => $clients,
        ]);
    }

    /**
     * Get the tool's input schema. The spike takes no arguments.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
