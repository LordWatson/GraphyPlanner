<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step: client portal contacts (`Role::ClientReviewer`) are redirected to `/portal` after login
 * instead of the staff `/dashboard`. `redirect()->intended()` still takes priority for both roles,
 * so a client following a deep link (e.g. straight to a specific post) lands there instead of the
 * portal home.
 */
class LoginResponse implements LoginResponseContract
{
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();

        $organization = $user?->organization;

        $home = match ($user?->role) {
            Role::ClientReviewer => '/portal',
            // Step 15.1: platform staff have no org workspace — they land on their own console.
            Role::SuperAdmin => '/super-admin',
            // Step B-2.2: a business workspace that hasn't finished (or skipped) its guided setup
            // lands there first.
            default => match (true) {
                $organization?->needsOnboarding() => '/welcome-setup',
                // Step B-4.2: a business workspace lands on its own "Today" home, never the
                // agency dashboard.
                $organization?->isBusiness() === true => '/today',
                default => '/dashboard',
            },
        };

        return redirect()->intended($home);
    }
}
