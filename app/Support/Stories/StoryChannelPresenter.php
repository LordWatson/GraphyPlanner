<?php

namespace App\Support\Stories;

use App\Models\PostTarget;

/**
 * Step S-5.1 — the one place a channel's assisted-lane state is turned into something a list, a
 * calendar or a pill can show. Every surface that lists channels (calendar, content board, client
 * posts tab, My work, the editor, the portal) reads it from here, so they can never disagree.
 *
 * The state is derived (`PostTarget::handoffState()`), never stored. Eager-load
 * `PostTarget::HANDOFF_STATE_RELATIONS` on the targets, with their `post` set, before calling.
 */
final class StoryChannelPresenter
{
    /** A Story with extras that will be posted by hand at its time. */
    public const NEEDS_HAND_POSTING = 'needs_hand_posting';

    /** Its handoff is open and due: somebody should be posting it now. */
    public const READY_TO_POST = 'ready_to_post';

    /** Published, and it was a person who confirmed it. */
    public const POSTED_BY_HAND = 'posted_by_hand';

    /**
     * The labels the frontend's `storyHandoff` status kind mirrors (`resources/js/lib/status.ts`).
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::NEEDS_HAND_POSTING => 'Needs posting by hand',
        self::READY_TO_POST => 'Ready to post',
        self::POSTED_BY_HAND => 'Posted by hand',
    ];

    /**
     * What the studio sees: `{state, label}`, or null for a channel that publishes itself.
     *
     * @return array{state: string, label: string}|null
     */
    public static function state(PostTarget $target): ?array
    {
        $state = $target->handoffState();

        return $state === null ? null : ['state' => $state, 'label' => self::LABELS[$state]];
    }

    /**
     * What a client sees: the same, except that "ready to post" is the studio's business — to the
     * client that Story still simply "needs posting by hand".
     *
     * @return array{state: string, label: string}|null
     */
    public static function clientState(PostTarget $target): ?array
    {
        $state = $target->handoffState();

        if ($state === self::READY_TO_POST) {
            $state = self::NEEDS_HAND_POSTING;
        }

        return $state === null ? null : ['state' => $state, 'label' => self::LABELS[$state]];
    }

    /**
     * What a client-facing page shows beside a Story's preview (review link, portal): the extras
     * as a read-only list and who will post it. Null for a channel that is not posted by hand.
     *
     * @return array{extras: list<array{key: string, label: string, value: string}>, summary: string, placement_note: string|null, posted_by: string}|null
     */
    public static function clientStory(PostTarget $target): ?array
    {
        if (! $target->handoff_required) {
            return null;
        }

        $extras = $target->storyExtras();

        return [
            'extras' => $extras->checklist(),
            'summary' => $extras->summary(),
            'placement_note' => $extras->placementNote,
            'posted_by' => $target->handoffRecipient()->value,
        ];
    }
}
