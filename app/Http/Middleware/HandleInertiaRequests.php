<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Services\Publishing\PlatformCapabilities;
use App\Services\SharedPropsCache;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
        // Every user-derived prop is a closure: this middleware runs *before* route middleware, so
        // reading the user eagerly here would miss a sign-in done by route middleware — the
        // `preview` middleware logs the visitor in, and a cold first `/preview/*` request used to
        // share `auth.user = null`. Inertia resolves closures when the response is built.
        $user = fn (): ?User => $request->user();
        $cache = app(SharedPropsCache::class);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn (): array => [
                'user' => self::sharedUser($user()),
                // Step 17.4: chapter gating for the sidebar and ⌘K palette (kept under `auth`, since
                // many pages pass their own top-level `can` prop, which would replace a shared one).
                'can' => [
                    'viewAnalytics' => self::canViewAnalytics($user()),
                ],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'preview' => $request->is('preview', 'preview/*'),
            'client' => function () use ($user): ?array {
                $current = $user();

                return $current && $current->role === Role::ClientReviewer && $current->client
                    ? ['name' => $current->client->name]
                    : null;
            },
            // Client portal chrome: the "Approvals" nav badge. A separate key (not part of `client`)
            // because several portal pages pass their own `client` page prop, which would replace it.
            'portal' => function () use ($user, $cache): ?array {
                $current = $user();

                return $current && $current->role === Role::ClientReviewer && $current->client_id
                    ? [
                        'pendingApprovals' => $cache->pendingApprovals($current->client_id),
                        // Phase 18: the "Grids" nav item shows once the client has a grid to see.
                        'grids' => $cache->portalGrids($current->client_id),
                    ]
                    : null;
            },
            // Shared on every Inertia response so the notification bell's unread badge is always
            // up to date without a dedicated round-trip; the dropdown's own list is lazy-loaded
            // from `notifications.index` only once opened.
            // Step I-2.6: cached per user and busted on notify/read (see SharedPropsCache).
            'unreadNotificationsCount' => fn (): int => ($current = $user()) ? $cache->unreadNotifications($current) : 0,
            // The organization's default currency (ISO 4217 code), shared so every page can
            // format currency fields consistently without re-fetching the organization.
            'currency' => fn (): string => $user()?->organization->default_currency ?? 'USD',
            // Step I-3.3 / M-2.1: the platform capability registry (`config/platforms.php`) — the
            // one source the editor counters, checklist mirror and previews read. Sent once per
            // page load and kept by the client across visits.
            'platforms' => Inertia::once(fn (): array => app(PlatformCapabilities::class)->toFrontend()),
        ];
    }

    /**
     * Step I-2.6: an explicit whitelist, so a new `users` column never reaches every page's props
     * just because it exists.
     *
     * @return array<string, mixed>|null
     */
    private static function sharedUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => null,
            'role' => $user->role?->value,
            'org_id' => $user->org_id,
            'client_id' => $user->client_id,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
        ];
    }

    private static function canViewAnalytics(?User $user): bool
    {
        $organization = $user?->organization;

        return $user !== null && $organization !== null && $user->can('viewAnalytics', $organization);
    }
}
