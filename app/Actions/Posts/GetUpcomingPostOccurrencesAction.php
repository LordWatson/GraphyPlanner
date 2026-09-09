<?php

namespace App\Actions\Posts;

use App\Enums\Role;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Carbon;

class GetUpcomingPostOccurrencesAction
{
    /**
     * Build a short list of the next scheduled post targets for the dashboard's calendar
     * snippet, scoped the same way as the full calendar (`CalendarController`): a
     * `Role::ClientReviewer` only sees their own client's posts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(User $user, int $days = 7, int $limit = 5): array
    {
        $orgTimezone = $user->organization?->default_timezone ?? 'UTC';
        $now = Carbon::now();

        $query = Post::query()
            ->where('org_id', $user->org_id)
            ->with(['client:id,name', 'targets' => fn ($q) => $q->orderBy('scheduled_at_utc'), 'targets.socialAccount']);

        if ($user->role === Role::ClientReviewer) {
            $query->where('client_id', $user->client_id);
        }

        $posts = $query->get();

        $occurrences = [];

        foreach ($posts as $post) {
            foreach ($post->targets as $target) {
                $utc = $target->scheduled_at_utc;

                if (! $utc || $utc->lt($now) || $utc->gt($now->clone()->addDays($days))) {
                    continue;
                }

                $account = $target->socialAccount;

                $occurrences[] = [
                    'post_id' => $post->id,
                    'target_id' => $target->id,
                    'client_name' => $post->client?->name,
                    'platform' => $account?->platform?->value,
                    'handle' => $account?->handle,
                    'date' => $utc->clone()->setTimezone($orgTimezone)->toDateString(),
                    'time' => $utc->clone()->setTimezone($orgTimezone)->format('H:i'),
                    'scheduled_at_utc' => $utc->toIso8601String(),
                ];
            }
        }

        usort($occurrences, fn (array $a, array $b) => $a['scheduled_at_utc'] <=> $b['scheduled_at_utc']);

        return array_slice($occurrences, 0, $limit);
    }
}
