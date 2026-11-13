<?php

namespace App\Actions\Stories;

use App\Enums\StoryHandoffOutcome;
use App\Models\StoryHandoff;
use Illuminate\Support\Facades\Log;

/**
 * Step S-2.5 — close the open handoffs of the given channels as `cancelled`, without touching the
 * channels themselves. Used wherever a post is taken back or re-sent: a recall (unschedule, move),
 * and a channel that has just left the assisted lane and is going to the vendor instead. The link
 * stops working at once rather than at the next sweep.
 */
class CancelStoryHandoffsAction
{
    /**
     * @param  list<int>  $targetIds
     * @return int how many handoffs were closed
     */
    public function __invoke(array $targetIds, string $why): int
    {
        if ($targetIds === []) {
            return 0;
        }

        $open = StoryHandoff::query()
            ->withoutGlobalScopes()
            ->whereIn('post_target_id', $targetIds)
            ->open()
            ->get(['id', 'org_id', 'post_id', 'post_target_id']);

        if ($open->isEmpty()) {
            return 0;
        }

        $closed = StoryHandoff::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $open->modelKeys())
            ->open()
            ->update(['outcome' => StoryHandoffOutcome::Cancelled, 'completed_at' => now()]);

        Log::info('Story handoffs cancelled', [
            'org_id' => $open->first()->org_id,
            'post_ids' => $open->pluck('post_id')->unique()->values()->all(),
            'story_handoff_ids' => $open->modelKeys(),
            'count' => $closed,
            'reason' => $why,
        ]);

        return $closed;
    }
}
