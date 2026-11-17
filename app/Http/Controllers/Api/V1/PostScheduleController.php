<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Posts\EvaluatePostChecklistAction;
use App\Actions\Posts\PostStatusTransitionMap;
use App\Actions\Posts\TransitionPostStatusAction;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\Api\ApiResponse;
use App\Support\Api\StoryChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/posts/{post}/schedule` — hand an approved post to the publisher.
 *
 * Its own controller and its own scope (`write:schedule`), because this is the one write on the
 * API that reaches the outside world: once scheduled, a post goes to real audiences at a real
 * time. Editing a draft is recoverable; this is not.
 *
 * It re-runs the **full** pre-publish checklist server-side before anything is enqueued — the same
 * `EvaluatePostChecklistAction` the web request is gated by, and the same rule
 * `SchedulePostTool` follows for MCP: no surface gets a shortcut past the gate. Then
 * `TransitionPostStatusAction` does the work, so the dispatch id, the activity log entry and the
 * `PublishPostJob` enqueue are identical to a human pressing the button.
 */
class PostScheduleController extends Controller
{
    public function __invoke(
        Request $request,
        Post $post,
        EvaluatePostChecklistAction $evaluateChecklist,
        TransitionPostStatusAction $transition,
    ): JsonResponse {
        $this->authorize('transition', [$post, PostStatus::Scheduled]);

        if (! PostStatusTransitionMap::isAllowedFor($post, PostStatus::Scheduled)) {
            return ApiResponse::error(
                'invalid_transition',
                sprintf('A post in "%s" cannot be scheduled — it must be approved first.', $post->status->value),
                409,
            );
        }

        $checklist = $evaluateChecklist($post);

        if (! $checklist['passed']) {
            $outstanding = array_values(array_map(
                fn (array $item): string => $item['label'],
                array_filter(
                    $checklist['items'],
                    // Soft items are advisory and never block a human either.
                    fn (array $item): bool => ! $item['passed'] && ! ($item['soft'] ?? false),
                ),
            ));

            // The outstanding items are listed, not summarised: a client that cannot see *what*
            // failed has no way to fix it except opening the UI, which defeats having an API.
            return ApiResponse::error(
                'checklist_failed',
                'The pre-schedule checklist must pass first.',
                409,
                ['outstanding' => $outstanding],
            );
        }

        try {
            $post = $transition($post, PostStatus::Scheduled, $request->user());
        } catch (ValidationException $e) {
            return ApiResponse::error(
                'invalid_transition',
                'Scheduling rejected: '.implode(' ', $e->validator->errors()->all()),
                422,
            );
        }

        $post->load(PostController::CHANNELS);

        return ApiResponse::item([
            ...PostController::present($post),
            // The earliest channel's instant: "when does this start going out".
            'scheduled_at' => $post->targets()->whereNotNull('scheduled_at_utc')->min('scheduled_at_utc'),
        ], [
            // Step S-5.4: "scheduled" does not mean "will publish itself" for a story that is
            // posted by hand, so say so where an integration cannot miss it.
            'notes' => array_filter([StoryChannels::note($post)]),
        ]);
    }
}
