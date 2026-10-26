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

/**
 * Phase MCP-3.3 — move a post through the workflow, reusing `TransitionPostStatusAction` and the same
 * explicit `PostStatusTransitionMap` the UI uses, so an illegal transition is rejected identically.
 * **Via MCP the reachable states are capped at `approved`** — scheduling and publishing require the
 * stronger `WriteSchedule` scope and the `schedule_post` tool (MCP-4), never this one. Requires the
 * `WriteDrafts` scope and the `transition` policy.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('transition_post_status')]
#[Title('Transition post status')]
#[Description('Moves a post to a new status (up to approved): idea, draft, internal_review, waiting_client, changes_requested, approved or archived. Scheduling/publishing are NOT reachable here — use schedule_post. Requires the write:drafts scope.')]
class TransitionPostStatusTool extends McpTool
{
    /**
     * The states MCP may drive a post into in this phase — everything up to approval, plus archive.
     * Scheduling and the publish pipeline are intentionally excluded (MCP-4, WriteSchedule).
     *
     * @var list<PostStatus>
     */
    private const CAPPED = [
        PostStatus::Idea,
        PostStatus::Draft,
        PostStatus::InternalReview,
        PostStatus::WaitingClient,
        PostStatus::ChangesRequested,
        PostStatus::Approved,
        PostStatus::Archived,
    ];

    public function __construct(private readonly TransitionPostStatusAction $transition) {}

    /**
     * @return list<McpScope>
     */
    protected function requiredScopes(): array
    {
        return [McpScope::WriteDrafts];
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
            'status' => ['required', 'string', 'in:'.implode(',', array_map(fn (PostStatus $s) => $s->value, self::CAPPED))],
            'comment' => ['nullable', 'string', 'max:3000'],
        ]);

        $to = PostStatus::from($validated['status']);

        $post = Post::query()
            ->withoutOrgScope()
            ->where('org_id', $org->id)
            ->where('id', $validated['post_id'])
            ->first();

        if ($post === null) {
            return Response::error('No post with that id exists in this organization.');
        }

        if ($user->cannot('transition', [$post, $to])) {
            return Response::error('You are not allowed to move this post to '.$to->value.'.');
        }

        // Same legality map the UI uses — reject illegal moves before touching the Action.
        if (! PostStatusTransitionMap::isAllowedFor($post, $to)) {
            return Response::error(sprintf('A post in "%s" cannot move to "%s".', $post->status->value, $to->value));
        }

        try {
            $post = ($this->transition)($post, $to, $user, $validated['comment'] ?? null);
        } catch (ValidationException $e) {
            return Response::error('Transition rejected: '.implode(' ', $e->validator->errors()->all()));
        }

        return Response::structured([
            'post_id' => $post->id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'edit_url' => route('posts.edit', $post),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The post to transition.'),
            'status' => $schema->string()
                ->enum(array_map(fn (PostStatus $s) => $s->value, self::CAPPED))
                ->required()
                ->description('The target status (capped at approved; scheduling/publishing are not reachable here).'),
            'comment' => $schema->string()->description('Optional transition note (supports @-mentions).'),
        ];
    }
}
