<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/**
 * Mirrors `App\Http\Responses\LoginResponse` for the two-factor challenge flow: client portal
 * contacts (`Role::ClientReviewer`) land on `/portal` instead of `/dashboard`, with
 * `redirect()->intended()` still taking priority for a deep link.
 */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
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
            return new JsonResponse('', 204);
        }

        $user = $request->user();

        $home = $user?->role === Role::ClientReviewer ? '/portal' : '/dashboard';

        return redirect()->intended($home);
    }
}
