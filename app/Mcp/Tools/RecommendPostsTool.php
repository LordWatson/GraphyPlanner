<?php

namespace App\Mcp\Tools;

use App\Actions\AI\RecommendPostsAction;
use App\Enums\McpScope;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\Client;
use App\Services\AI\AiAccess;
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
 * Phase MCP-5.1 — AI-recommended post ideas for a client, reusing `RecommendPostsAction` (guardrails,
 * caching, budget all intact). It only *suggests* — turning an idea into a draft is a separate,
 * explicit `create_draft_post` call. Requires the `Ai` scope; over-budget / AI-disabled return a
 * clean non-technical message. Banned phrases are filtered inside the Action.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('recommend_posts')]
#[Title('Recommend posts')]
#[Description('Returns AI-suggested post ideas (title, description, platforms, suggested caption and time) for a client. Suggestions only — use create_draft_post to act on one. Requires the ai scope.')]
#[IsReadOnly]
class RecommendPostsTool extends McpTool
{
    public function __construct(
        private readonly RecommendPostsAction $recommendPosts,
        private readonly AiAccess $aiAccess,
    ) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::Ai];
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

        if (! $this->aiAccess->canUse($user)) {
            return Response::error('AI assistance is not enabled for this organization.');
        }

        $validated = $request->validate([
            'client_id' => ['required', 'integer'],
        ]);

        $client = Client::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['client_id'])
            ->first();

        if ($client === null) {
            return Response::error('No client with that id exists in this organization.');
        }

        try {
            $ideas = ($this->recommendPosts)($client);
        } catch (AiBudgetExceededException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'client_id' => $client->id,
            'ideas' => $ideas->jsonSerialize(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('The client to recommend posts for.'),
        ];
    }
}
