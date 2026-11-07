<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an organisation that requires two-factor authentication to its word.
 *
 * Fortify gives each person the means to enrol; nothing made it mandatory. This is the other half:
 * when an owner turns the organisation switch on, every member who has not enrolled is sent to
 * their security page and can go nowhere else until they have.
 *
 * Global (appended to the `web` group) rather than named on each route group, because a security
 * control that has to be remembered on every new route is one that will eventually be forgotten.
 *
 * Deliberately exempt:
 * - **Super admins** — platform staff with no organisation of their own to be governed by.
 * - **Client reviewers** — external client contacts in the portal. Forcing an authenticator app on
 *   somebody else's client would lock them out of their own approvals with nobody able to help;
 *   "all members" means the agency's team.
 * - The pages needed to comply (security settings, the Fortify 2FA endpoints), to get back in
 *   (password confirmation, verification) or to leave (logout) — otherwise this redirects to a page
 *   it has itself blocked.
 */
class EnsureTwoFactorEnrolled
{
    /**
     * Route names that stay reachable while a member is locked out. Prefix match on `*`.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        'security.edit',
        'user-password.update',
        'two-factor.*',
        'password.confirm',
        'logout',
        'profile.*',
        'appearance.edit',
        'verification.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->mustEnrol($user) || $this->isAllowed($request)) {
            return $next($request);
        }

        // An XHR or a form POST cannot usefully follow a redirect to a settings page, and silently
        // bouncing one would look like a bug rather than a policy. 403 says what happened.
        if ($request->expectsJson() || ! $request->isMethodSafe()) {
            abort(403, __('Your organisation requires two-factor authentication. Set it up in your security settings.'));
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('Your organisation requires two-factor authentication. Set it up to carry on.'),
        ]);

        return redirect()->route('security.edit');
    }

    private function mustEnrol(User $user): bool
    {
        return $user->org_id !== null
            && ! $user->isSuperAdmin()
            && ! $user->hasRole(Role::ClientReviewer)
            // `organization` is already loaded on almost every request (shared Inertia props), so
            // this is not an extra query in practice.
            && (bool) $user->organization?->require_two_factor
            && ! $user->hasEnabledTwoFactorAuthentication();
    }

    private function isAllowed(Request $request): bool
    {
        // `routeIs` is false for an unnamed route, which is what we want: anything unnamed is not
        // part of the enrolment flow.
        return $request->routeIs(...self::ALLOWED);
    }
}
