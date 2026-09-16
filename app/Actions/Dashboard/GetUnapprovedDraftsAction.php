<?php

namespace App\Actions\Dashboard;

use App\Enums\PostStatus;
use App\Enums\Role;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Str;

class GetUnapprovedDraftsAction
{
    /**
     * Build the dashboard's "Unapproved drafts" card: posts still in draft, internal review, or
     * changes-requested — i.e. not yet approved/scheduled — newest first. Scoped like
     * `GetUpcomingPostOccurrencesAction`: a `Role::ClientReviewer` only sees their own client's posts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(User $user, int $limit = 6): array
    {
        $statuses = [PostStatus::Draft, PostStatus::InternalReview, PostStatus::ChangesRequested];

        $query = Post::query()
            ->where('org_id', $user->org_id)
            ->whereIn('status', $statuses)
            ->with('client:id,name')
            ->latest('updated_at');

        if ($user->role === Role::ClientReviewer) {
            $query->where('client_id', $user->client_id);
        }

        return $query->limit($limit)->get()
            ->map(fn (Post $post) => [
                'post_id' => $post->id,
                'title' => $post->master_caption ? Str::limit($post->master_caption, 60) : 'Untitled post',
                'client_name' => $post->client?->name,
                'status' => $post->status->value,
                'status_label' => $post->status->label(),
            ])
            ->values()
            ->all();
    }
}
