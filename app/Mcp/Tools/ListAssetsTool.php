<?php

namespace App\Mcp\Tools;

use App\Actions\Assets\SearchClientAssetsAction;
use App\Enums\AssetType;
use App\Enums\McpScope;
use App\Models\Asset;
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
 * Phase MCP-2.4 — list a client's media assets as SAFE metadata only (filename, type, dimensions,
 * rights…), reusing `SearchClientAssetsAction`. The signed download `url`, the storage `disk`/`path`
 * and thumbnails are deliberately never exposed (MCP-1.6) — an assistant sees that an asset exists
 * and its shape, not a way to fetch the bytes. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('list_assets')]
#[Title('List client assets')]
#[Description('Lists a client\'s media assets as safe metadata (id, filename, type, mime, dimensions, rights, AI-generated flag). Never exposes download URLs or storage paths. Optional search, type and campaign_id filters. Read-only.')]
#[IsReadOnly]
class ListAssetsTool extends McpTool
{
    public function __construct(private readonly SearchClientAssetsAction $searchAssets) {}

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
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (AssetType $t) => $t->value, AssetType::cases()))],
            'campaign_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        $page = $this->searchAssets->__invoke(
            $client,
            $validated['search'] ?? null,
            isset($validated['type']) ? AssetType::from($validated['type']) : null,
            $validated['page'] ?? 1,
            $validated['campaign_id'] ?? null,
        );

        return Response::structured([
            'organization' => $org->name,
            'client_id' => $client->id,
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'assets' => array_map(
                fn (Asset $asset): array => McpPresenters::asset($asset),
                $page->items(),
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client whose assets to list.'),
            'search' => $schema->string()->description('Match the original filename.'),
            'type' => $schema->string()
                ->enum(array_map(fn (AssetType $t) => $t->value, AssetType::cases()))
                ->description('Only assets of this type (image, video, document, other).'),
            'campaign_id' => $schema->integer()->description('Only assets attached to this campaign.'),
            'page' => $schema->integer()->description('Page number (24 per page).'),
        ];
    }
}
