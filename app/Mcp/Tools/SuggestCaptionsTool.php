<?php

namespace App\Mcp\Tools;

use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Enums\McpScope;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\Post;
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
 * Phase MCP-5.1 — AI caption suggestions for a post, reusing `SuggestCaptionsForPostAction`
 * (guardrails, caching, budget intact). Suggestions only — writing a caption back onto the post is a
 * separate explicit `update_post` call. Requires the `Ai` scope; over-budget / AI-disabled return a
 * clean non-technical message; banned phrases are filtered inside the Action.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('suggest_captions')]
#[Title('Suggest captions')]
#[Description('Returns AI caption suggestions (caption, hashtags, alt text, first comment) for a post. Suggestions only — use update_post to apply one. Requires the ai scope.')]
#[IsReadOnly]
class SuggestCaptionsTool extends McpTool
{
    public function __construct(
        private readonly SuggestCaptionsForPostAction $suggestCaptions,
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
            'post_id' => ['required', 'integer'],
        ]);

        $post = Post::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['post_id'])
            ->first();

        if ($post === null) {
            return Response::error('No post with that id exists in this organization.');
        }

        try {
            $suggestions = ($this->suggestCaptions)($post);
        } catch (AiBudgetExceededException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'post_id' => $post->id,
            'suggestions' => $suggestions->jsonSerialize(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The post to suggest captions for.'),
        ];
    }
}
