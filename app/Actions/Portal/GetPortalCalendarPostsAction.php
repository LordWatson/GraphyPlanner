<?php

namespace App\Actions\Portal;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Calendar\CalendarWindow;
use Illuminate\Support\Collection;

/**
 * The client portal calendar's posts: always scoped to the contact's own `client_id` and to
 * `PostStatus::clientVisible()` — an internal-only `$status` is ignored rather than honoured, so
 * pre-review drafts can never be requested through the filters. Eager-loads exactly what
 * `BuildCalendarOccurrencesAction` needs.
 */
class GetPortalCalendarPostsAction
{
    /**
     * @return Collection<int, Post>
     */
    public function __invoke(
        User $user,
        CalendarWindow $window,
        ?PostStatus $status = null,
        ?int $campaignId = null,
        ?Platform $platform = null,
    ): Collection {
        $status = $status?->isClientVisible() ? $status : null;

        // Step I-2.3: only the visible window, like the internal calendar.
        return Post::query()
            ->where('org_id', $user->org_id)
            ->where('client_id', $user->client_id)
            ->when(
                $status,
                fn ($query) => $query->where('status', $status),
                fn ($query) => $query->whereIn('status', PostStatus::clientVisible()),
            )
            ->when($campaignId, fn ($query) => $query->where('campaign_id', $campaignId))
            ->when($platform, fn ($query) => $query->whereHas(
                'targets.socialAccount',
                fn ($q) => $q->where('platform', $platform),
            ))
            ->whereHas('targets', fn ($target) => $target->whereBetween(CalendarWindow::TARGET_DATE_COLUMN, $window->targetDateRange()))
            ->with([
                'client:id,name',
                'campaign:id,name',
                'targets' => fn ($target) => $target
                    ->whereBetween(CalendarWindow::TARGET_DATE_COLUMN, $window->targetDateRange())
                    ->select(['id', 'post_id', 'social_account_id', 'scheduled_local_date', 'scheduled_local_time', 'scheduled_at_utc'])
                    ->orderBy('id'),
                'targets.socialAccount:id,platform,handle,timezone',
            ])
            ->orderBy('id')
            ->get(['id', 'org_id', 'client_id', 'campaign_id', 'status', 'master_caption']);
    }
}
