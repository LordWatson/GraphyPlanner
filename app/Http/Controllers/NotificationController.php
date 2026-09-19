<?php

namespace App\Http\Controllers;

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
    /**
     * Return the authenticated user's most recent notifications as JSON, for the bell dropdown
     * to lazy-load beyond what's already shared on every Inertia response.
     */
    public function index(Request $request): array
    {
        $user = $request->user();

        return [
            'notifications' => $user->notifications()
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? null,
                    'body' => $notification->data['body'] ?? null,
                    'url' => $notification->data['url'] ?? null,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
            'unread_count' => $user->unreadNotifications()->count(),
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
        }

        return redirect($model->data['url'] ?? route('dashboard'));
    }

    /**
     * Mark every one of the user's unread notifications as read (the "mark all as read" action).
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
