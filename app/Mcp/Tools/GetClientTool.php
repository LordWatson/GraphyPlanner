<?php

namespace App\Mcp\Tools;

use App\Enums\McpScope;
use App\Models\Client;
use App\Support\Mcp\McpPresenters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-2.1 — a single client's profile plus its computed health (status + headline reason +
 * the viewer-safe reason list), reusing the exact `ClientHealthService`/`Client::healthFor()` the
 * web UI uses so health never drifts. Billing-sensitive fields appear only when the acting member
 * may see them (`ClientPolicy::viewBilling`). Owner-only health reasons are already dropped by
 * `healthFor($viewer)`. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_client')]
#[Title('Get client')]
#[Description('Returns one client\'s profile and computed health (status, headline reason and the viewer-safe reason list). Billing fields are included only for members allowed to see them. Read-only.')]
#[IsReadOnly]
class GetClientTool extends McpTool
{
    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::Read];
    }

    protected function handleAuthorized(Request $request): Response|ResponseFactory
    {
        $org = McpTenant::organization();

        if ($org === null) {
            return Response::error('No organization is available for this connection.');
        }

        $user = $this->actAsOrganizationMember();

        if ($user === null) {
            return Response::error('No member is available to authorize this connection.');
        }

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with('owner:id,name')
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        // healthFor() recomputes/materializes and drops owner-only reasons for non-Owner viewers.
        $health = $client->healthFor($user);

        return Response::structured(
            McpPresenters::clientProfile($client, $health, $user->can('viewBilling', $client)),
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The id of the client to fetch.'),
        ];
    }
}
