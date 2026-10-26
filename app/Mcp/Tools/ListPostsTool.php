<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\ListPostsAction;
use App\Enums\McpScope;
use App\Enums\PostStatus;
use App\Models\Post;
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
 * Phase MCP-2.2 — list the organization's posts as compact rows, reusing `ListPostsAction`'s filter
 * query (the same one that powers the `/content` hub) so the filters behave exactly as the web UI
 * does. Tenancy is belt-and-suspenders: the acting member is bound (so `OrganizationScope` applies)
 * AND the query is pinned with an explicit `->where('posts.org_id', …)` so it is scoped even without
 * a member. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('list_posts')]
#[Title('List posts')]
#[Description('Lists the organization\'s posts as structured rows (id, client, campaign, status, caption excerpt, platforms). Filters: client_id, campaign_id, status, platform, from/to (YYYY-MM-DD). Read-only.')]
#[IsReadOnly]
class ListPostsTool extends McpTool
{
    private const LIMIT = 100;

    public function __construct(private readonly ListPostsAction $listPosts) {}

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
            'client_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (PostStatus $s) => $s->value, PostStatus::cases()))],
            'platform' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $posts = $this->listPosts->query([
            'client' => $validated['client_id'] ?? null,
            'campaign' => $validated['campaign_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'platform' => $validated['platform'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ])
            ->where('posts.org_id', $org->id)
            ->with(['client:id,name', 'campaign:id,name', 'targets.socialAccount:id,platform', 'assets:id'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Post $post): array => McpPresenters::postRow($post))
            ->all();

        return Response::structured([
            'organization' => $org->name,
            'count' => count($posts),
            'posts' => $posts,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->description('Only posts for this client.'),
            'campaign_id' => $schema->integer()->description('Only posts in this campaign.'),
            'status' => $schema->string()
                ->enum(array_map(fn (PostStatus $s) => $s->value, PostStatus::cases()))
                ->description('Only posts in this status. Omit to list all non-archived posts.'),
            'platform' => $schema->string()->description('Only posts targeting this platform (e.g. instagram, tiktok).'),
            'from' => $schema->string()->format('date')->description('Earliest scheduled local date (YYYY-MM-DD).'),
            'to' => $schema->string()->format('date')->description('Latest scheduled local date (YYYY-MM-DD).'),
        ];
    }
}
