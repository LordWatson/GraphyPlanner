<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 15.1 — guards every `super-admin/*` route (alias `super_admin`): only platform staff
 * (`Role::SuperAdmin`) may enter; every organization role gets a 403.
 */
class EnsureUserIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'This area is only available to platform administrators.');
        }

        return $next($request);
    }
}
