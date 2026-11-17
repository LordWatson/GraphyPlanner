<?php

namespace App\Support\Stories;

use App\Models\Post;
use App\Models\PostTarget;

/**
 * Step S-5.1 — the assisted-lane state for list surfaces that hold a whole post (calendar
 * occurrences, content rows, the client posts tab, My work). It only decides *which* channel to
 * ask and hands each one its post; the state itself always comes from `StoryChannelPresenter`.
 *
 * Callers eager-load `targets.openStoryHandoff` and `targets.postedStoryHandoff`
 * (`PostTarget::HANDOFF_STATE_RELATIONS`) and select the targets' `status` and `handoff_required`.
 */
final class StoryPostPresenter
{
    /** Most urgent first: a row that stands for a whole post shows the first one it has. */
    private const URGENCY = [
        StoryChannelPresenter::READY_TO_POST,
        StoryChannelPresenter::NEEDS_HAND_POSTING,
        StoryChannelPresenter::POSTED_BY_HAND,
    ];

    /**
     * One channel of a post we already hold.
     *
     * @return array{state: string, label: string}|null
     */
    public static function channel(Post $post, PostTarget $target, bool $forClient = false): ?array
    {
        // `handoffState()` reads the post's status; lazy loading is off, so hand it over.
        $target->setRelation('post', $post);

        return $forClient ? StoryChannelPresenter::clientState($target) : StoryChannelPresenter::state($target);
    }

    /**
     * The post's most urgent channel state, or null when every channel publishes itself.
     *
     * @return array{state: string, label: string}|null
     */
    public static function summary(Post $post, bool $forClient = false): ?array
    {
        $best = null;

        foreach ($post->targets as $target) {
            $state = self::channel($post, $target, $forClient);

            if ($state !== null && ($best === null || self::rank($state) < self::rank($best))) {
                $best = $state;
            }
        }

        return $best;
    }

    /**
     * @param  array{state: string, label: string}  $state
     */
    private static function rank(array $state): int
    {
        return (int) array_search($state['state'], self::URGENCY, true);
    }
}
