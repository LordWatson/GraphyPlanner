<?php

namespace App\Actions\Stories;

use App\Actions\Publishing\SyncPublishStatusAction;
use App\Enums\StoryHandoffOutcome;
use App\Models\StoryHandoff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Step S-2.4 — "Something went wrong": the poster could not post an assisted Story.
 *
 * Closes the handoff with their note and fails the channel through `SyncPublishStatusAction`, so
 * the post resolves and the team hears about it the way they hear about any failed publish
 * (`PostPublishFailed`). The note is on the channel's error, where the editor shows it.
 */
class ReportStoryHandoffProblemAction
{
    /** `PublishFailureReason::StoryProblemReported` is recognised from this prefix. */
    public const ERROR_PREFIX = 'The poster reported a problem: ';

    public function __construct(private readonly SyncPublishStatusAction $sync = new SyncPublishStatusAction) {}

    /**
     * @throws ValidationException when the handoff is no longer open
     */
    public function __invoke(StoryHandoff $handoff, string $note, ?User $by = null): StoryHandoff
    {
        $note = Str::limit(trim($note), 500, '');

        return DB::transaction(function () use ($handoff, $note, $by): StoryHandoff {
            $locked = StoryHandoff::lockForChange($handoff->id);
            $target = $locked?->target;

            if ($locked === null || $target === null || ! $locked->isOpen() || ! $locked->isCurrent()) {
                throw ValidationException::withMessages([
                    'handoff' => __('This link is no longer active. Open the post in Alima to see where it’s up to.'),
                ]);
            }

            $locked->update([
                'outcome' => StoryHandoffOutcome::Problem,
                'problem_note' => $note,
                'completed_at' => now(),
                'completed_by_user_id' => $by?->id,
            ]);

            Log::warning('Story handoff: the poster reported a problem', [
                'org_id' => $locked->org_id,
                'post_id' => $locked->post_id,
                'post_target_id' => $locked->post_target_id,
                'story_handoff_id' => $locked->id,
                'user_id' => $by?->id,
            ]);

            ($this->sync)($target, false, self::ERROR_PREFIX.$note);

            return $locked;
        });
    }
}
