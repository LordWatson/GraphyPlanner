<?php

namespace App\Http\Controllers;

use App\Services\SharedPropsCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification bell: lists a user's own database notifications and, on click, marks one as
 * read then redirects to wherever that notification points (e.g. a post's editor/portal page).
 * Notifications are always scoped to `$request->user()->notifications()` — a user can never read
 * or mark another user's notification.
 */
class NotificationController extends Controller
{
    /** How many notifications a page of the centre returns. */
    private const PER_PAGE = 20;

    /**
     * Return a page of the authenticated user's notifications as JSON, for the bell dropdown / the
     * notification centre to lazy-load. Step M-6.7: paginated with "load more" (`?before=<id>`) and
     * an optional `?filter=unread`.
     *
     * @return array{notifications: array<int, array<string, mixed>>, unread_count: int, next_before: string|null, has_more: bool}
     */
    public function index(Request $request): array
    {
        $user = $request->user();
        $before = $request->query('before');
        // The cursor is a notification id; page by (created_at, id) so identical timestamps still
        // paginate deterministically.
        $cursor = is_string($before) && $before !== ''
            ? $user->notifications()->whereKey($before)->first(['created_at', 'id'])
            : null;

        $query = $user->notifications()
            ->when($request->query('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($cursor !== null, fn ($q) => $q->where(fn ($inner) => $inner
                ->where('created_at', '<', $cursor->created_at)
                ->orWhere(fn ($tie) => $tie->where('created_at', $cursor->created_at)->where('id', '<', $cursor->id))))
            ->latest()
            ->latest('id');

        $rows = $query->limit(self::PER_PAGE + 1)->get();
        $hasMore = $rows->count() > self::PER_PAGE;
        $page = $rows->take(self::PER_PAGE);

        return [
            'notifications' => $page
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? null,
                    'body' => $notification->data['body'] ?? null,
                    'url' => $notification->data['url'] ?? null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'unread_count' => $user->unreadNotifications()->count(),
            'next_before' => $hasMore ? (string) $page->last()?->id : null,
            'has_more' => $hasMore,
        ];
    }

    /**
     * Mark one notification as read, then redirect to the page it points to. 404s if the
     * notification doesn't belong to the acting user or has no stored `url`.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $model = $request->user()->notifications()->whereKey($notification)->firstOrFail();

        if (! $model->read_at) {
            $model->markAsRead();
            SharedPropsCache::forgetUnreadNotifications($request->user()->id);
        }

        return redirect($model->data['url'] ?? route('dashboard'));
    }

    /**
     * Mark every one of the user's unread notifications as read (the "mark all as read" action).
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        SharedPropsCache::forgetUnreadNotifications($request->user()->id);

        return back();
    }
}
