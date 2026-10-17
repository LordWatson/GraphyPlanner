<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 15.3 — appended to the whole `web` group, so it covers every org surface (studio, client
 * portal, settings) without each route group having to opt in. A signed-in member of a suspended
 * organization sees only the "contact support" page; signing out still works. Guests and
 * super admins (org-less) pass straight through.
 */
class EnsureOrganizationNotSuspended
{
    /**
     * Routes a locked-out member can still use.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = ['logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $user?->org_id ? $user->organization : null;

        if (! $organization?->isSuspended() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        Log::info('Blocked request from suspended organization', [
            'org_id' => $organization->id,
            'user_id' => $user->id,
            'path' => $request->path(),
        ]);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'This organization is suspended.');
        }

        return Inertia::render('organizations/suspended', [
            'organization' => ['name' => $organization->name],
            'reason' => $organization->suspension_reason,
            'supportEmail' => config('mail.support_address') ?: config('mail.from.address'),
        ])->toResponse($request)->setStatusCode(403);
    }
}
