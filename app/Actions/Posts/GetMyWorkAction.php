<?php

namespace App\Actions\Posts;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Posts\ContentHubPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Step M-5.4 — one person's work across every client: what's assigned to them, waiting on their
 * review, due soon or overdue, and where they've been mentioned. The org scope keeps it to the
 * viewer's own organization; every bucket is their own records only.
 */
class GetMyWorkAction
{
    public const DUE_SOON_DAYS = 3;

    /** Statuses still in flight — published and archived posts drop off "my work". */
    private const ACTIVE = [
        PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview, PostStatus::WaitingClient,
        PostStatus::ChangesRequested, PostStatus::Approved, PostStatus::Scheduled, PostStatus::Failed,
    ];

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function __invoke(User $user): array
    {
        $dueBy = Carbon::now()->addDays(self::DUE_SOON_DAYS)->endOfDay();

        // Due soon / overdue — assigned to me, with a deadline within reach. Shown first, so the
        // plain "assigned" bucket leaves these out to avoid repeating them.
        $due = $this->base()
            ->where('assignee_id', $user->id)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $dueBy)
            ->orderBy('due_at')
            ->get();

        $assigned = $this->base()
            ->where('assignee_id', $user->id)
            ->whereNotIn('id', $due->modelKeys())
            ->orderByDesc('updated_at')
            ->get();

        $review = $this->base()
            ->where('reviewer_id', $user->id)
            ->where('status', PostStatus::InternalReview->value)
            ->orderByDesc('updated_at')
            ->get();

        $mentioned = $this->base()
            ->whereHas('comments.mentionedUsers', fn (Builder $query) => $query->whereKey($user->id))
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return [
            'due' => $due->map(fn (Post $post) => ContentHubPresenter::row($post, $user))->all(),
            'review' => $review->map(fn (Post $post) => ContentHubPresenter::row($post, $user))->all(),
            'assigned' => $assigned->map(fn (Post $post) => ContentHubPresenter::row($post, $user))->all(),
            'mentioned' => $mentioned->map(fn (Post $post) => ContentHubPresenter::row($post, $user))->all(),
        ];
    }

    /**
     * @return Builder<Post>
     */
    private function base(): Builder
    {
        return Post::query()
            ->whereIn('status', array_map(fn (PostStatus $status) => $status->value, self::ACTIVE))
            ->with(['client:id,name', 'campaign', 'creator:id,name', 'assignee:id,name', 'targets.socialAccount', 'assets:id']);
    }
}
