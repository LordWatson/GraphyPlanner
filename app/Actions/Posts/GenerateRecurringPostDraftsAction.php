<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step M-5.8 — the daily sweep that keeps recurring posts going. For every recurring post that has
 * already published and hasn't yet spawned its next occurrence (the chain's "leaf"), it drops the
 * next occurrence as a **draft** (via `RecyclePostAction`), each target advanced by one interval.
 * Never a scheduled post — a human always reviews and sends it.
 *
 * Idempotent: once a leaf has a recurrence child it's no longer a leaf, so a second run on the same
 * day creates nothing. A per-post failure is logged and skipped so one bad post can't stop the rest.
 */
class GenerateRecurringPostDraftsAction
{
    public function __invoke(RecyclePostAction $recycle = new RecyclePostAction): int
    {
        $leaves = Post::query()
            ->withoutGlobalScopes()
            ->whereNotNull('recurrence')
            ->whereIn('status', [PostStatus::Published, PostStatus::PartiallyPublished])
            // A recurrence child (recurrence set) means this leaf already spawned its next; a
            // manual recycle (recurrence null) doesn't count. `withTrashed` so a deleted draft
            // never causes a duplicate.
            ->whereDoesntHave('recycledInto', $this->hasRecurrenceChild(...))
            ->with('targets.socialAccount')
            ->get();

        $created = 0;

        foreach ($leaves as $leaf) {
            try {
                $recycle($leaf, null, $leaf->recurrence);
                $created++;
            } catch (Throwable $e) {
                Log::warning('Recurring post draft failed', [
                    'post_id' => $leaf->id,
                    'org_id' => $leaf->org_id,
                    'recurrence' => $leaf->recurrence?->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Recurring post drafts generated', ['created' => $created]);

        return $created;
    }

    /**
     * A leaf already has its next occurrence if a recurrence child exists (trashed ones count, so a
     * deleted draft never causes a duplicate). Typed so `withTrashed()` resolves on the relation.
     *
     * @param  Builder<Post>  $query
     */
    private function hasRecurrenceChild(Builder $query): void
    {
        $query->withTrashed()->whereNotNull('recurrence');
    }
}
