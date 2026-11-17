<?php

namespace App\Actions\Stories;

use App\Enums\PostTargetStatus;
use App\Enums\StoryHandoffOutcome;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\StoryHandoff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Step S-2.2 — give an assisted Story channel its handoff, when its post starts publishing.
 *
 * Called from the one fan-out (`PublishPostJob::dispatchTargets()`) for every channel with
 * `handoff_required`, in place of sending it to the vendor (decision D1). The channel is left
 * exactly as it is — `pending`, no vendor id — so the post waits in `publishing` (D2).
 *
 * Idempotent per publish attempt: `PublishPostJob` retries and re-claims, and a second call for the
 * same attempt returns the same row. A *new* attempt (a retry, a recall) closes whatever was open
 * before as `cancelled` and opens a fresh one (D5).
 *
 * `due_at` is the channel's own time, not the post's (D4): a post with a feed channel at 09:00 and
 * this Story at 10:00 starts publishing at 09:00, and the Story's poster is told at 10:00 by the
 * sweep (`ProcessStoryHandoffsAction`), not now.
 *
 * Only the hash of the link's token is stored. The token is derived from the row
 * (`StoryHandoff::plainToken()`), so `IssueStoryHandoffLinkAction` can hand the same link out
 * again later without anything having kept it.
 *
 * Lock order, wherever a Story action takes more than one lock: post, then channel, then handoff
 * (`StoryHandoff::lockForChange()`, `VendorScheduleService::recallAwaitingHandoffs()`).
 */
class OpenStoryHandoffAction
{
    public function __construct(private readonly ResolveStoryPosterAction $resolvePoster = new ResolveStoryPosterAction) {}

    public function __invoke(PostTarget $target): ?StoryHandoff
    {
        return DB::transaction(function () use ($target): ?StoryHandoff {
            /** @var Post|null $post */
            $post = Post::query()->whereKey($target->post_id)->lockForUpdate()->first();

            /** @var PostTarget|null $locked */
            $locked = PostTarget::query()->whereKey($target->id)->lockForUpdate()->first();

            if ($post === null || $locked === null || $locked->status !== PostTargetStatus::Pending
                || $locked->external_post_id !== null || blank($locked->publish_attempt_id)) {
                Log::info('Story handoff not opened: channel is not waiting on a publish attempt', [
                    'post_id' => $target->post_id,
                    'post_target_id' => $target->id,
                    'status' => $locked?->status->value,
                ]);

                return null;
            }

            $attemptId = (string) $locked->publish_attempt_id;

            // A handoff left open by an earlier attempt is stale the moment this one exists.
            StoryHandoff::query()
                ->withoutGlobalScopes()
                ->where('post_target_id', $locked->id)
                ->where('publish_attempt_id', '!=', $attemptId)
                ->open()
                ->update(['outcome' => StoryHandoffOutcome::Cancelled, 'completed_at' => now()]);

            $existing = StoryHandoff::query()
                ->withoutGlobalScopes()
                ->where('post_target_id', $locked->id)
                ->where('publish_attempt_id', $attemptId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $locked->setRelation('post', $post);
            $poster = ($this->resolvePoster)($locked);

            $dueAt = $locked->scheduled_at_utc ?? now();
            $window = max(1, (int) config('publishing.story_handoff.window_minutes', 120));

            $handoff = StoryHandoff::query()->create([
                'org_id' => $post->org_id,
                'client_id' => $post->client_id,
                'post_id' => $post->id,
                'post_target_id' => $locked->id,
                'publish_attempt_id' => $attemptId,
                // A placeholder nobody holds the plain form of; replaced just below.
                'token_hash' => hash('sha256', Str::random(64)),
                'recipient_type' => $poster['type'],
                'user_id' => $poster['user']?->id,
                'due_at' => $dueAt,
                // A retry long after the original time still gets a full window to be posted in.
                'expires_at' => $dueAt->copy()->max(now())->addMinutes($window),
            ]);

            // The link's token is derived from the row's id, so its hash can only be set now.
            $handoff->forceFill(['token_hash' => hash('sha256', $handoff->plainToken())])->save();

            Log::info('Story handoff opened', [
                'org_id' => $post->org_id,
                'post_id' => $post->id,
                'post_target_id' => $locked->id,
                'story_handoff_id' => $handoff->id,
                'recipient_type' => $handoff->recipient_type->value,
                'user_id' => $handoff->user_id,
                'due_at' => $handoff->due_at->toIso8601String(),
            ]);

            return $handoff;
        });
    }
}
