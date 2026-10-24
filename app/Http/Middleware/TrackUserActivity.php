<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step M-8.2 — stamps `users.last_active_at` so the partner usage view can count weekly-active
 * users. Appended to the `web` group, it runs on every authenticated page but writes at most once
 * per throttle window per user (a cache key gates it), so an active session costs one `UPDATE` every
 * few minutes rather than one per request.
 *
 * Only real organization members are tracked: the org-less super admin (never counted as a user) and
 * the read-only preview fixture owner are skipped, so neither mutates the table or skews the figure.
 * The write runs after the response so it never slows a request, and updates the column directly
 * (no model events) to stay cheap.
 */
class TrackUserActivity
{
    /**
     * How long a stamp is considered fresh before the next request re-writes it.
     */
    private const THROTTLE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user instanceof User || $user->org_id === null || $user->isSuperAdmin() || $user->isPreviewUser()) {
            return;
        }

        $fresh = Cache::add('user-active:'.$user->id, true, self::THROTTLE_SECONDS);

        if (! $fresh) {
            return;
        }

        $now = CarbonImmutable::now();

        // A direct update (no model events / timestamps touched) — the cheapest possible write, and
        // it must never fail a request, so a database hiccup is swallowed silently.
        try {
            User::query()->whereKey($user->id)->update(['last_active_at' => $now]);
            $user->setAttribute('last_active_at', $now);
        } catch (\Throwable) {
            Cache::forget('user-active:'.$user->id);
        }
    }
}
