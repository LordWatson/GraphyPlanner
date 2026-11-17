<?php

namespace App\Actions\Stories;

use App\Enums\StoryHandoffOutcome;
use App\Jobs\PublishPostJob;
use App\Models\StoryHandoff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step S-2.3 (decision D4) — the one sweep that drives every time-based change to a handoff.
 *
 * `PublishPostJob` opens a handoff when the *post* starts publishing, which is the earliest
 * channel's time. This runs every minute and does the rest, per open handoff:
 *
 * 1. **stale** (its attempt was replaced, or the post left `publishing`) → closed as `cancelled`,
 *    the channel untouched;
 * 2. **expired** (`expires_at` passed) → `ExpireStoryHandoffAction` fails the channel — also
 *    while paused, so a hold that outlasts the window never leaves a Story pending;
 * 3. **paused** (archived client, suspended or deleted organization) → not notified or nudged. It
 *    resumes if the hold lifts inside its window;
 * 4. **due and not yet notified** → notified, flagged late if the sweep was behind;
 * 5. **notified, not shared, a while ago** → nudged, once.
 *
 * Stamp, then send: `notified_at` / `nudged_at` are written under a row lock in their own
 * transaction before anything is sent, so a crash or a second server can never notify twice. The
 * cost is that a crash between the two loses that one message — and the nudge, then the loud
 * failure at expiry, are what catch it.
 */
class ProcessStoryHandoffsAction
{
    public function __construct(
        private readonly ExpireStoryHandoffAction $expire = new ExpireStoryHandoffAction,
        private readonly NotifyStoryPosterAction $notify = new NotifyStoryPosterAction,
    ) {}

    /**
     * @return array{notified: int, nudged: int, expired: int, cancelled: int, failed: int}
     */
    public function __invoke(): array
    {
        $counts = ['notified' => 0, 'nudged' => 0, 'expired' => 0, 'cancelled' => 0, 'failed' => 0];
        $now = Carbon::now();
        $grace = max(0, (int) config('publishing.story_handoff.late_grace_minutes', 10));
        $nudgeAfter = max(1, (int) config('publishing.story_handoff.nudge_after_minutes', 15));

        $handoffs = StoryHandoff::query()
            ->withoutGlobalScopes()
            ->open()
            ->with(['post.organization', 'post.client', 'target'])
            ->lazyById(200);

        foreach ($handoffs as $handoff) {
            // One handoff that throws must not hold up every handoff after it, every minute.
            try {
                $step = $this->process($handoff, $now, $grace, $nudgeAfter);

                if ($step !== null) {
                    $counts[$step]++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;

                Log::error('Story handoff sweep: a handoff could not be processed', [
                    'org_id' => $handoff->org_id,
                    'post_id' => $handoff->post_id,
                    'post_target_id' => $handoff->post_target_id,
                    'story_handoff_id' => $handoff->id,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);

                report($e);
            }
        }

        if (array_sum($counts) > 0) {
            Log::info('Story handoff sweep finished', $counts);
        }

        return $counts;
    }

    /**
     * One handoff, one step. Returns which counter it moved, or null when there was nothing to do.
     *
     * @return 'notified'|'nudged'|'expired'|'cancelled'|null
     */
    private function process(StoryHandoff $handoff, Carbon $now, int $grace, int $nudgeAfter): ?string
    {
        if (! $handoff->isCurrent()) {
            return $this->cancel($handoff) > 0 ? 'cancelled' : null;
        }

        // Before the pause check: a handoff whose window has passed expires whether or not its
        // client is archived, so a Story never sits pending for as long as a hold lasts.
        if ($handoff->expires_at->lessThanOrEqualTo($now)) {
            return ($this->expire)($handoff) !== null ? 'expired' : null;
        }

        if ($handoff->post === null || PublishPostJob::pausedReason($handoff->post) !== null) {
            return null;
        }

        if ($handoff->due_at->greaterThan($now)) {
            return null;
        }

        if ($handoff->notified_at === null) {
            if (! $this->stamp($handoff, 'notified_at')) {
                return null;
            }

            ($this->notify)($handoff, late: $now->greaterThan($handoff->due_at->copy()->addMinutes($grace)));

            return 'notified';
        }

        if ($handoff->nudged_at === null && $handoff->shared_at === null
            && $handoff->notified_at->lessThanOrEqualTo($now->copy()->subMinutes($nudgeAfter))
            && $this->stamp($handoff, 'nudged_at')) {
            ($this->notify)($handoff, nudge: true);

            return 'nudged';
        }

        return null;
    }

    /**
     * Claim one notification: under a row lock, set `$column` if it is still empty and the handoff
     * is still open. False when another run got there first.
     */
    private function stamp(StoryHandoff $handoff, string $column): bool
    {
        return DB::transaction(function () use ($handoff, $column): bool {
            /** @var StoryHandoff|null $locked */
            $locked = StoryHandoff::query()->withoutGlobalScopes()->whereKey($handoff->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isOpen() || $locked->getAttribute($column) !== null) {
                return false;
            }

            $locked->forceFill([$column => now()])->save();
            $handoff->setAttribute($column, $locked->getAttribute($column));

            return true;
        });
    }

    private function cancel(StoryHandoff $handoff): int
    {
        $closed = StoryHandoff::query()
            ->withoutGlobalScopes()
            ->whereKey($handoff->id)
            ->open()
            ->update(['outcome' => StoryHandoffOutcome::Cancelled, 'completed_at' => now()]);

        if ($closed > 0) {
            Log::info('Stale story handoff cancelled', [
                'org_id' => $handoff->org_id,
                'post_id' => $handoff->post_id,
                'post_target_id' => $handoff->post_target_id,
                'story_handoff_id' => $handoff->id,
            ]);
        }

        return $closed;
    }
}
