<?php

namespace App\Actions\Posts;

use App\Enums\Role;
use App\Models\Post;
use App\Models\User;
use App\Policies\PostPolicy;
use App\Support\Calendar\CalendarWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Step I-2.3 — the internal calendar's posts for one visible window. Only posts with a target
 * dated inside the window are loaded, each with only those targets and only the columns
 * `BuildCalendarOccurrencesAction` reads, so the payload follows what's on screen rather than the
 * org's history. Undated posts are listed separately (`unscheduled()`), paginated.
 *
 * Scoping matches the old controller: active clients only, the viewer's org, and a
 * `Role::ClientReviewer` pinned to their own client.
 */
class GetCalendarPostsAction
{
    public const UNSCHEDULED_PER_PAGE = 20;

    /**
     * @param  array{client_id?: int|null, campaign_id?: int|null, status?: string|null, platform?: string|null}  $filters
     * @return Collection<int, Post>
     */
    public function __invoke(User $user, array $filters, CalendarWindow $window): Collection
    {
        return $this->filtered($user, $filters)
            ->whereHas('targets', fn ($target) => $target->whereBetween(CalendarWindow::TARGET_DATE_COLUMN, $window->targetDateRange()))
            ->with([
                'client:id,name',
                'campaign:id,name,code,colour',
                'targets' => fn ($target) => $target
                    ->whereBetween(CalendarWindow::TARGET_DATE_COLUMN, $window->targetDateRange())
                    ->select(['id', 'post_id', 'social_account_id', 'scheduled_local_date', 'scheduled_local_time', 'scheduled_at_utc'])
                    ->orderBy('id'),
                'targets.socialAccount:id,platform,handle,timezone',
            ])
            ->orderBy('posts.id')
            ->get(['posts.id', 'posts.org_id', 'posts.client_id', 'posts.campaign_id', 'posts.status', 'posts.master_caption']);
    }

    /**
     * Which of the given posts the viewer may drag to another day — `PostPolicy::reschedule` as
     * one query instead of a policy call per post.
     *
     * @param  list<int>  $postIds
     * @return list<int>
     */
    public function reschedulableIds(User $user, array $postIds): array
    {
        if ($postIds === [] || ! PostPolicy::canRescheduleAny($user)) {
            return [];
        }

        return array_values(Post::query()
            ->where('org_id', $user->org_id)
            ->whereIn('id', $postIds)
            ->reschedulable()
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());
    }

    /**
     * Posts with no dated target (or no targets at all) — invisible on the grid, so the calendar
     * lists them in a drawer.
     *
     * @param  array{client_id?: int|null, campaign_id?: int|null, status?: string|null, platform?: string|null}  $filters
     * @return LengthAwarePaginator<int, Post>
     */
    public function unscheduled(User $user, array $filters, int $page = 1): LengthAwarePaginator
    {
        return $this->undated($user, $filters)
            ->with(['client:id,name'])
            ->orderByDesc('posts.updated_at')
            ->orderByDesc('posts.id')
            ->paginate(self::UNSCHEDULED_PER_PAGE, ['posts.id', 'posts.org_id', 'posts.client_id', 'posts.status', 'posts.master_caption', 'posts.updated_at'], 'unscheduled_page', $page);
    }

    /**
     * @param  array{client_id?: int|null, campaign_id?: int|null, status?: string|null, platform?: string|null}  $filters
     */
    public function unscheduledCount(User $user, array $filters): int
    {
        return $this->undated($user, $filters)->count();
    }

    /**
     * @param  array{client_id?: int|null, campaign_id?: int|null, status?: string|null, platform?: string|null}  $filters
     * @return Builder<Post>
     */
    private function undated(User $user, array $filters): Builder
    {
        return $this->filtered($user, $filters)
            ->whereDoesntHave('targets', fn (Builder $target) => $target->whereNotNull('scheduled_local_date'));
    }

    /**
     * @param  array{client_id?: int|null, campaign_id?: int|null, status?: string|null, platform?: string|null}  $filters
     * @return Builder<Post>
     */
    private function filtered(User $user, array $filters): Builder
    {
        $query = Post::query()
            ->forActiveClients()
            ->where('posts.org_id', $user->org_id);

        if ($user->role === Role::ClientReviewer) {
            $query->where('posts.client_id', $user->client_id);
        } elseif (! empty($filters['client_id'])) {
            $query->where('posts.client_id', $filters['client_id']);
        }

        if (! empty($filters['campaign_id'])) {
            $query->where('posts.campaign_id', $filters['campaign_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('posts.status', $filters['status']);
        }

        if (! empty($filters['platform'])) {
            $platform = $filters['platform'];
            $query->whereHas('targets.socialAccount', fn (Builder $account) => $account->where('platform', $platform));
        }

        return $query;
    }
}
