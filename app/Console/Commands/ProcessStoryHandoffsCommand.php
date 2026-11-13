<?php

namespace App\Console\Commands;

use App\Actions\Stories\ProcessStoryHandoffsAction;
use Illuminate\Console\Command;

/**
 * Step S-2.3 — the per-minute sweep behind Stories that are posted by hand: tells the poster when
 * a Story is due, nudges once, and fails the channel when nobody confirms inside the window.
 * Idempotent, so running it by hand to unstick something is always safe (see `ops.md`).
 */
class ProcessStoryHandoffsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'stories:process-handoffs';

    /**
     * @var string
     */
    protected $description = 'Notify, nudge and expire the handoffs of stories that are posted by hand';

    public function handle(ProcessStoryHandoffsAction $action): int
    {
        $counts = $action();

        $this->info(sprintf(
            'Story handoffs: %d notified, %d nudged, %d expired, %d cancelled, %d failed.',
            $counts['notified'],
            $counts['nudged'],
            $counts['expired'],
            $counts['cancelled'],
            $counts['failed'],
        ));

        // A handoff that threw is logged and reported; the heartbeat still records the run, since
        // every other handoff was processed.
        return self::SUCCESS;
    }
}
