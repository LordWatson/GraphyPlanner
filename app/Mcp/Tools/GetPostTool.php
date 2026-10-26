<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Enums\McpScope;
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
 * Phase MCP-2.2 — a full post: master content, every target rendered in BOTH the account's own local
 * time and the org timezone (so a post with a London and a Dubai target reports both independent
 * times — the invariant MCP-2.2 proves), the attached assets as safe metadata, and the live
 * checklist state from the exact `EvaluatePostChecklistAction` the editor uses, so the reported
 * checklist never drifts. Read-only; requires `Read`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('get_post')]
#[Title('Get post')]
#[Description('Returns a full post: caption, hashtags, each target\'s scheduled time in both the account\'s local timezone and the org timezone, attached asset metadata, and the live pre-publish checklist. Read-only.')]
#[IsReadOnly]
class GetPostTool extends McpTool
{
    public function __construct(private readonly EvaluatePostChecklistAction $evaluateChecklist) {}

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
            'post_id' => ['required', 'integer'],
        ]);

        $post = Post::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->with([
                'client:id,name',
                'campaign:id,name',
                'creator:id,name',
                'assignee:id,name',
                'targets.socialAccount:id,platform,handle,timezone',
                'assets',
            ])
            ->where('id', $validated['post_id'])
            ->first();

        if ($post === null) {
            return Response::error('No post with that id exists in this organization.');
        }

        $checklist = ($this->evaluateChecklist)($post);
        $orgTimezone = $org->default_timezone ?: 'UTC';

        return Response::structured(
            McpPresenters::postDetail($post, $checklist, $orgTimezone),
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The id of the post to fetch.'),
        ];
    }
}
