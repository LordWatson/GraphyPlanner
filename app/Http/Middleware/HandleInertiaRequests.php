<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'preview' => $request->is('preview', 'preview/*'),
            'client' => $user && $user->role === Role::ClientReviewer && $user->client
                ? ['name' => $user->client->name]
                : null,
            // Shared on every Inertia response so the notification bell's unread badge is always
            // up to date without a dedicated round-trip; the dropdown's own list is lazy-loaded
            // from `notifications.index` only once opened.
            'unreadNotificationsCount' => $user ? $user->unreadNotifications()->count() : 0,
        ];
    }
}
