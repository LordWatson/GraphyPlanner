<?php

namespace App\Actions\Portal;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Step 6.4 — the client portal's dashboard payload. Reuses the shape of
 * `App\Actions\Home\GetNeedsAttentionItemsAction`, but scoped to the logged-in contact's own
 * `client_id` (never a value taken from the request, per `EnsureClientPortalAccess`) and
 * deliberately narrower: only outstanding approvals, recently requested changes, and upcoming
 * scheduled posts for their brand — no failed-publish or disconnected-account internals, which
 * stay Owner/Strategist-only per §3.
 */
class GetPortalDashboardItemsAction
{
    /**
     * @return array{
     *     waitingApprovals: array<int, array<string, mixed>>,
     *     changesRequested: array<int, array<string, mixed>>,
     *     upcomingPosts: array<int, array<string, mixed>>,
     * }
     */
    public function __invoke(User $user, int $upcomingDays = 14, int $upcomingLimit = 10): array
    {
        $posts = Post::query()
            ->where('org_id', $user->org_id)
            ->where('client_id', $user->client_id)
            ->with(['targets' => fn ($q) => $q->orderBy('scheduled_at_utc'), 'targets.socialAccount'])
            ->get();

        $waitingApprovals = $posts
            ->filter(fn (Post $post) => $post->status === PostStatus::WaitingClient)
            ->map(fn (Post $post) => $this->transformPost($post, 'Waiting on your approval'))
            ->values()
            ->all();

        $changesRequested = $posts
            ->filter(fn (Post $post) => $post->status === PostStatus::ChangesRequested)
            ->map(fn (Post $post) => $this->transformPost($post, 'Changes requested'))
            ->values()
            ->all();

        $orgTimezone = $user->organization?->default_timezone ?? 'UTC';
        $now = Carbon::now();
        $upcomingPosts = [];

        foreach ($posts as $post) {
            if ($post->status !== PostStatus::Scheduled) {
                continue;
            }

            foreach ($post->targets as $target) {
                $utc = $target->scheduled_at_utc;

                if (! $utc || $utc->lt($now) || $utc->gt($now->clone()->addDays($upcomingDays))) {
                    continue;
                }

                $account = $target->socialAccount;

                $upcomingPosts[] = [
                    'post_id' => $post->id,
                    'target_id' => $target->id,
                    'master_caption' => $post->master_caption,
                    'platform' => $account?->platform?->value,
                    'handle' => $account?->handle,
                    'date' => $utc->clone()->setTimezone($orgTimezone)->toDateString(),
                    'time' => $utc->clone()->setTimezone($orgTimezone)->format('H:i'),
                    'scheduled_at_utc' => $utc->toIso8601String(),
                ];
            }
        }

        usort($upcomingPosts, fn (array $a, array $b) => $a['scheduled_at_utc'] <=> $b['scheduled_at_utc']);

        return [
            'waitingApprovals' => $waitingApprovals,
            'changesRequested' => $changesRequested,
            'upcomingPosts' => array_slice($upcomingPosts, 0, $upcomingLimit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transformPost(Post $post, string $reason): array
    {
        $target = $post->targets->first();
        $account = $target?->socialAccount;

        return [
            'post_id' => $post->id,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            'reason' => $reason,
            'master_caption' => $post->master_caption,
            'platform' => $account?->platform?->value,
            'handle' => $account?->handle,
        ];
    }
}
