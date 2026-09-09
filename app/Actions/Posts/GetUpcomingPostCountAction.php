<?php

namespace App\Actions\Posts;

use App\Enums\Role;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Carbon;

class GetUpcomingPostCountAction
{
    /**
     * Count the distinct posts that have at least one target scheduled in the next `$days` days,
     * scoped the same way as `GetUpcomingPostOccurrencesAction`: a `Role::ClientReviewer` only
     * sees their own client's posts.
     */
    public function __invoke(User $user, int $days = 7): int
    {
        $now = Carbon::now();

        $query = Post::query()
            ->where('org_id', $user->org_id)
            ->whereHas('targets', function ($q) use ($now, $days) {
                $q->whereBetween('scheduled_at_utc', [$now, $now->clone()->addDays($days)]);
            });

        if ($user->role === Role::ClientReviewer) {
            $query->where('client_id', $user->client_id);
        }

        return $query->count();
    }
}
