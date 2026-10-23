<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Models\Organization;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Step M-6.5 (Phase 9) — when has a post been waiting on a decision too long? A post in
 * `waiting_client` or `changes_requested` is "overdue" once it has sat there longer than the org's
 * `approval_sla_hours`. "Entered this status at" is read from the latest `PostActivityLog` row, so
 * no new column is needed. An org with no SLA set never has an overdue post.
 */
class PostApprovalSlaService
{
    /** The statuses an SLA applies to — a post waiting on either side of the review. */
    public const WATCHED_STATUSES = [PostStatus::WaitingClient, PostStatus::ChangesRequested];

    public function isOverdue(Post $post): bool
    {
        if (! in_array($post->status, self::WATCHED_STATUSES, true)) {
            return false;
        }

        $hours = $post->organization->approval_sla_hours;

        if ($hours === null || $hours <= 0) {
            return false;
        }

        $enteredAt = $post->activityLogs()->max('created_at');

        return $enteredAt !== null && Carbon::parse($enteredAt)->addHours($hours)->isPast();
    }

    /**
     * A query for an org's overdue posts, computed set-based (one query) for the reminder job and
     * batch overdue checks. `$cappedToSlaWindow` keeps it symmetrical with `isOverdue`.
     *
     * @return Builder<Post>
     */
    public function overdueQuery(Organization $organization): Builder
    {
        $hours = $organization->approval_sla_hours;

        /** @var Builder<Post> $query */
        $query = Post::query()->whereRaw('0 = 1');

        if ($hours === null || $hours <= 0) {
            return $query;
        }

        $threshold = now()->subHours($hours);

        return Post::query()
            ->where('org_id', $organization->id)
            ->whereIn('status', array_map(fn (PostStatus $status) => $status->value, self::WATCHED_STATUSES))
            ->whereRaw(
                '(select max(created_at) from post_activity_logs where post_activity_logs.post_id = posts.id) <= ?',
                [$threshold],
            );
    }
}
