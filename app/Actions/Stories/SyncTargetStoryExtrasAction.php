<?php

namespace App\Actions\Stories;

use App\Models\PostTarget;
use App\Support\Publishing\StoryExtras;
use Illuminate\Support\Facades\Log;

/**
 * Step S-1.2 — the one writer of `post_targets.handoff_required` (decision D3).
 *
 * Whether a channel is posted by hand is "it is a Story, on a platform the assisted lane is
 * offered on, with at least one extra" (`PostTarget::needsHandoff()`). The publish jobs need that
 * in SQL, so it is stored — and a stored copy of a derived fact is only safe with a single writer.
 * Everything that saves a channel's extras, or changes the format of a channel that may have
 * some, comes through here. (The two bulk creators — CSV import and grid tiles — set a format on
 * channels they have just created, which have no extras, so the column default already holds.)
 *
 *  - `__invoke()` stores the submitted extras (normalised through `StoryExtras`) and re-derives
 *    the flag;
 *  - `refresh()` re-derives the flag alone, for a save that may have changed the format. Moving a
 *    channel off the Story format keeps its extras (the user may switch back) but clears the flag.
 *
 * Step S-1.1: with the kill switch off, submitted extras are ignored and the flag is written
 * false; what is already stored is left alone, and so is a Story that is already waiting on a
 * person (`isAwaitingHandoff()`), which finishes by hand.
 */
class SyncTargetStoryExtrasAction
{
    /**
     * @param  array<string, mixed>|null  $input  the raw `story_extras` payload; null clears them
     */
    public function __invoke(PostTarget $target, ?array $input): PostTarget
    {
        if (! (bool) config('publishing.story_handoff.enabled', false)) {
            return $this->refresh($target);
        }

        $target->story_extras = StoryExtras::fromArray($input)->toArray();

        return $this->refresh($target);
    }

    public function refresh(PostTarget $target): PostTarget
    {
        // Switched off, a Story that is already with a person is left exactly as it is: it
        // finishes by hand rather than suddenly going to the vendor without its link.
        if (! (bool) config('publishing.story_handoff.enabled', false) && $target->isAwaitingHandoff()) {
            return $target;
        }

        $target->loadMissing('socialAccount');
        $target->handoff_required = $target->needsHandoff();

        if (! $target->isDirty(['story_extras', 'handoff_required'])) {
            return $target;
        }

        $flagChanged = $target->isDirty('handoff_required');
        $target->save();

        if ($flagChanged) {
            Log::info('Story channel lane changed', [
                'org_id' => $target->socialAccount?->org_id,
                'post_id' => $target->post_id,
                'post_target_id' => $target->id,
                'handoff_required' => $target->handoff_required,
            ]);
        }

        return $target;
    }
}
