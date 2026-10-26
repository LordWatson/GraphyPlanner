<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\PostStatusTransitionMap;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\McpScope;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Support\Mcp\McpTenant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Phase MCP-4.3 — take a scheduled post back off the schedule (to `approved`), reusing the same
 * `TransitionPostStatusAction`: leaving the schedule clears the publish dispatch id so the queued job
 * no-ops, and any vendor-held channels are recalled first. Where the adapter refuses a cancel, the
 * Action throws and this returns a clean MCP error. Destructive-hinted; requires `WriteSchedule`.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('cancel_scheduled_post')]
#[Title('Cancel scheduled post')]
#[Description('Takes a scheduled post back off the schedule (returns it to approved), recalling any vendor-held channels. Rejected cleanly where a channel has already gone out. Requires the write:schedule scope.')]
#[IsDestructive]
class CancelScheduledPostTool extends McpTool
{
    public function __construct(private readonly TransitionPostStatusAction $transition) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::WriteSchedule];
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

        if ($post->status !== PostStatus::Scheduled) {
            return Response::error('Only a scheduled post can be cancelled; this one is "'.$post->status->value.'".');
        }

        if ($user->cannot('transition', [$post, PostStatus::Approved])) {
            return Response::error('You are not allowed to cancel this post.');
        }

        if (! PostStatusTransitionMap::isAllowedFor($post, PostStatus::Approved)) {
            return Response::error('This post cannot be taken off the schedule.');
        }

        try {
            $post = ($this->transition)($post, PostStatus::Approved, $user);
        } catch (ValidationException $e) {
            return Response::error('Cancel rejected: '.implode(' ', $e->validator->errors()->all()));
        }

        return Response::structured([
            'post_id' => $post->id,
            'status' => $post->status->value,
            'edit_url' => route('posts.edit', $post),
            'message' => 'Post taken off the schedule and returned to approved.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The scheduled post to cancel.'),
        ];
    }
}
