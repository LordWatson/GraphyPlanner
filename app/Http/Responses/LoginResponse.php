<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

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
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();

        $home = $user?->role === Role::ClientReviewer ? '/portal' : '/dashboard';

        return redirect()->intended($home);
    }
}
