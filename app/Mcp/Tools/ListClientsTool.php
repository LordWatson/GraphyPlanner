<?php

namespace App\Mcp\Tools;

use App\Enums\ClientStatus;
use App\Enums\McpScope;
use App\Models\Client;
use App\Support\Mcp\McpPresenters;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Phase MCP-0.2 → MCP-2.1 — list the organization's clients (brands) as a compact structured list,
 * with optional status / owner / tag / search filters. Tenancy is the resolved token's org
 * (`McpTenant`), applied explicitly with `withoutOrgScope()->where('org_id', …)` so the result is
 * exactly that org's clients regardless of whether a member is bound. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('list_clients')]
#[Title('List clients')]
#[Description('Lists the organization\'s clients (brands) as a structured list (id, name, status, health, owner). Optional filters: status, owner (user id), tag, search. Read-only.')]
#[IsReadOnly]
class ListClientsTool extends McpTool
{
    private const LIMIT = 200;

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

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (ClientStatus $s) => $s->value, ClientStatus::cases()))],
            'owner' => ['nullable', 'integer'],
            'tag' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $clients = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with('owner:id,name')
            // Archived brands are dropped work: shown only when explicitly asked for by status.
            ->when(! isset($filters['status']), fn (Builder $q) => $q->where('status', '!=', ClientStatus::Archived->value))
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(isset($filters['owner']), fn (Builder $q) => $q->where('owner_user_id', $filters['owner']))
            ->when(isset($filters['tag']), fn (Builder $q) => $q->where('tags', 'like', '%"'.addcslashes($filters['tag'], '%_\\"').'"%'))
            ->when(isset($filters['search']), function (Builder $q) use ($filters): void {
                $like = '%'.addcslashes($filters['search'], '%_\\').'%';
                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('legal_name', 'like', $like));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Client $client): array => McpPresenters::clientRow($client))
            ->all();

        return Response::structured([
            'organization' => $org->name,
            'count' => count($clients),
            'clients' => $clients,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(array_map(fn (ClientStatus $s) => $s->value, ClientStatus::cases()))
                ->description('Only clients with this status. Omit to list all non-archived clients.'),
            'owner' => $schema->integer()->description('Only clients owned by this user id.'),
            'tag' => $schema->string()->description('Only clients carrying this tag.'),
            'search' => $schema->string()->description('Match client name or legal name.'),
        ];
    }
}
