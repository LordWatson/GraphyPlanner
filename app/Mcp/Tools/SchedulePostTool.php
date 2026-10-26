<?php

namespace App\Mcp\Tools;

use App\Actions\Posts\EvaluatePostChecklistAction;
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
 * Phase MCP-4.1 — schedule an approved post for publishing. This is an irreversible, outward-facing
 * action: it ships OFF by default behind the elevated `WriteSchedule` scope the Owner must grant, is
 * annotated destructive so clients prompt for confirmation, and records an elevated audit entry.
 *
 * It re-runs the FULL pre-publish checklist server-side (the exact `EvaluatePostChecklistAction` the
 * `scheduled` transition is gated by in the web request) before anything is enqueued — MCP gets no
 * shortcut — then uses the same `TransitionPostStatusAction`, which mints the publish dispatch id and
 * enqueues `PublishPostJob` for the earliest target's UTC instant.
 *
 * See .junie/plans/mcp-server.md.
 */
#[Name('schedule_post')]
#[Title('Schedule post')]
#[Description('Schedules an APPROVED post for publishing. Re-runs the full pre-publish checklist first; refuses if it does not pass. This is irreversible and outward-facing — requires the write:schedule scope. Returns the earliest scheduled UTC instant.')]
#[IsDestructive]
class SchedulePostTool extends McpTool
{
    public function __construct(
        private readonly EvaluatePostChecklistAction $evaluateChecklist,
        private readonly TransitionPostStatusAction $transition,
    ) {}

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

        if ($user->cannot('transition', [$post, PostStatus::Scheduled])) {
            return Response::error('You are not allowed to schedule this post.');
        }

        if (! PostStatusTransitionMap::isAllowedFor($post, PostStatus::Scheduled)) {
            return Response::error(sprintf('A post in "%s" cannot be scheduled — it must be approved first.', $post->status->value));
        }

        // The server re-runs the full checklist; MCP never bypasses the gate the UI enforces.
        $checklist = ($this->evaluateChecklist)($post);

        if (! $checklist['passed']) {
            $failed = array_values(array_filter(
                $checklist['items'],
                fn (array $item): bool => ! $item['passed'] && ! ($item['soft'] ?? false),
            ));

            return Response::error('The pre-schedule checklist must pass first. Outstanding: '.implode('; ', array_map(
                fn (array $item): string => $item['label'],
                $failed,
            )));
        }

        try {
            $post = ($this->transition)($post, PostStatus::Scheduled, $user);
        } catch (ValidationException $e) {
            return Response::error('Scheduling rejected: '.implode(' ', $e->validator->errors()->all()));
        }

        $earliest = $post->targets()->whereNotNull('scheduled_at_utc')->min('scheduled_at_utc');

        return Response::structured([
            'post_id' => $post->id,
            'status' => $post->status->value,
            'scheduled_at_utc' => $earliest,
            'edit_url' => route('posts.edit', $post),
            'message' => 'Post scheduled. It will publish automatically at the scheduled time.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->required()->description('The approved post to schedule.'),
        ];
    }
}
