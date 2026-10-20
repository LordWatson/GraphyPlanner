<?php

namespace App\Services;

use App\Enums\GridPlanStatus;
use App\Enums\PostStatus;
use App\Models\GridPlan;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Step I-2.6 — the two counts `HandleInertiaRequests` shares on every request (the notification
 * bell's unread badge and the portal's "Approvals" badge), cached briefly so a page view doesn't
 * pay for two `COUNT(*)`s.
 *
 * Both are busted where they change: a database notification being sent (listener in
 * `AppServiceProvider`) or read (`NotificationController`), and a post changing status or being
 * deleted (`Post::booted`). The TTL only bounds staleness if a write path is ever missed.
 */
class SharedPropsCache
{
    public const TTL_SECONDS = 60;

    public function unreadNotifications(User $user): int
    {
        return (int) Cache::remember(self::unreadKey($user->id), self::TTL_SECONDS, fn () => $user->unreadNotifications()->count());
    }

    public function pendingApprovals(int $clientId): int
    {
        return (int) Cache::remember(self::pendingApprovalsKey($clientId), self::TTL_SECONDS, fn () => Post::query()
            ->where('client_id', $clientId)
            ->where('status', PostStatus::WaitingClient)
            ->count());
    }

    /**
     * Phase 18: the portal's "Grids" nav item — how many grids the client can see, and how many
     * are waiting on them. Busted when a grid changes status or is deleted (`GridPlan::booted`).
     *
     * @return array{visible: int, waiting: int}
     */
    public function portalGrids(int $clientId): array
    {
        /** @var array{visible: int, waiting: int} $counts */
        $counts = Cache::remember(self::portalGridsKey($clientId), self::TTL_SECONDS, function () use ($clientId) {
            $statuses = GridPlan::query()
                ->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('client_id', $clientId)
                ->whereIn('status', GridPlanStatus::clientVisible())
                ->pluck('status');

            return [
                'visible' => $statuses->count(),
                'waiting' => $statuses->filter(fn (GridPlanStatus $status) => $status === GridPlanStatus::WaitingClient)->count(),
            ];
        });

        return $counts;
    }

    public static function forgetPortalGrids(int $clientId): void
    {
        Cache::forget(self::portalGridsKey($clientId));
    }

    public static function forgetUnreadNotifications(int $userId): void
    {
        Cache::forget(self::unreadKey($userId));
    }

    public static function forgetPendingApprovals(int $clientId): void
    {
        Cache::forget(self::pendingApprovalsKey($clientId));
    }

    private static function unreadKey(int $userId): string
    {
        return "shared-props:users:{$userId}:unread-notifications";
    }

    private static function portalGridsKey(int $clientId): string
    {
        return "shared-props:clients:{$clientId}:portal-grids";
    }

    private static function pendingApprovalsKey(int $clientId): string
    {
        return "shared-props:clients:{$clientId}:pending-approvals";
    }
}
