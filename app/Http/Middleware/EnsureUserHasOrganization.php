<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 15.1 — applied (alias `org.member`) to the org workspace routes: the studio, the client
 * portal and the organization-level settings pages. Everything there is scoped by the user's
 * `org_id`, so an account without one can't use it:
 *
 * - a super admin (org-less platform staff) is sent to their own console at `/super-admin`;
 * - any other org-less account gets a 403 rather than a half-broken, empty workspace.
 */
class EnsureUserHasOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return $request->expectsJson()
                ? abort(403, 'Platform administrators don’t have an organization workspace.')
                : to_route('super-admin.dashboard');
        }

        if ($user && $user->org_id === null) {
            abort(403, 'Your account isn’t part of an organization yet.');
        }

        return $next($request);
    }
}
