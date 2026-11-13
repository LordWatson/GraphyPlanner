<?php

namespace App\Actions\Stories;

use App\Enums\PostStatus;
use App\Enums\PostTargetStatus;
use App\Enums\StoryHandoffOutcome;
use App\Models\PostTarget;
use App\Models\StoryHandoff;

/**
 * Step S-4.5 / S-5.2 — where a channel's Story handoff is up to, for a signed-in member who came
 * from the bell, Needs attention or the editor rather than from the emailed link.
 *
 * Returns the handoff that matters for the channel's current publish attempt and one of:
 *
 *  - `open`     — there is a live handoff; a fresh link can be issued for it;
 *  - `not_due`  — the post is scheduled and the Story will be handed off at its time;
 *  - `posted`   — somebody already confirmed it;
 *  - `missed`   — the window passed (it can be retried, or marked as posted if it did go out);
 *  - `none`     — this channel is not waiting on anybody.
 */
class FindStoryHandoffForChannelAction
{
    public const OPEN = 'open';

    public const NOT_DUE = 'not_due';

    public const POSTED = 'posted';

    public const MISSED = 'missed';

    public const NONE = 'none';

    /**
     * @return array{state: string, handoff: StoryHandoff|null}
     */
    public function __invoke(PostTarget $target): array
    {
        $target->loadMissing('post');
        $post = $target->post;

        /** @var StoryHandoff|null $handoff */
        $handoff = filled($target->publish_attempt_id)
            ? StoryHandoff::query()
                ->withoutGlobalScopes()
                ->where('post_target_id', $target->id)
                ->where('publish_attempt_id', $target->publish_attempt_id)
                ->first()
            : null;

        if ($handoff !== null) {
            $handoff->setRelation('target', $target);

            if ($post !== null) {
                $handoff->setRelation('post', $post);
            }
        }

        $state = match (true) {
            $handoff !== null && $handoff->isOpen() && $handoff->isCurrent() => self::OPEN,
            $handoff?->outcome === StoryHandoffOutcome::Posted, $target->status === PostTargetStatus::Published => self::POSTED,
            $handoff?->outcome === StoryHandoffOutcome::Missed && $target->status === PostTargetStatus::Failed => self::MISSED,
            $target->handoff_required && $target->status === PostTargetStatus::Pending
                && $post !== null && in_array($post->status, [PostStatus::Scheduled, PostStatus::Publishing], true) => self::NOT_DUE,
            default => self::NONE,
        };

        return ['state' => $state, 'handoff' => $handoff];
    }
}
