<?php

namespace App\Http\Responses;

use App\Enums\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

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
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $user = $request->user();

        $home = match ($user?->role) {
            Role::ClientReviewer => '/portal',
            // Step 15.1: platform staff have no org workspace — they land on their own console.
            Role::SuperAdmin => '/super-admin',
            default => '/dashboard',
        };

        return redirect()->intended($home);
    }
}
