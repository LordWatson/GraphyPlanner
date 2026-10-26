<?php

namespace App\Mcp\Tools;

use App\Enums\McpScope;
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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-2.1 — a named client's Brand Brain (voice, audience, offer, visual style, music/hashtag
 * policy and content pillars) as markdown context, reusing the canonical `BrandBrain::toGrokContext()`
 * shape the AI module already consumes. The parameterised counterpart to `BrandBrainResource` (which
 * serves the org's first client only). Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_brand_brain')]
#[Title('Get client brand brain')]
#[Description('Returns a client\'s brand brain (voice, audience, offer, visual style, music/hashtag policy, content pillars) as markdown context. Read-only.')]
#[IsReadOnly]
class GetBrandBrainTool extends McpTool
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

        $this->actAsOrganizationMember();

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with('brandBrain')
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        if ($client->brandBrain === null) {
            return Response::error('That client has no brand brain yet.');
        }

        return Response::text($client->brandBrain->toGrokContext()['markdown']);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The id of the client whose brand brain to load.'),
        ];
    }
}
