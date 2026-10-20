<?php

namespace App\Http\Middleware;

use App\Support\Observability\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step I-16.1 / M-1.1 — web middleware (after the session has started): adds the signed-in
 * user's id and organization id to `Context`, so logs, queued jobs and error reports say whose
 * request it was. Ids only — never names or emails.
 */
class ShareRequestContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            Context::add(array_filter([
                TraceContext::USER_ID => $user->getAuthIdentifier(),
                TraceContext::ORG_ID => $user->org_id ?? null,
            ], fn ($value) => $value !== null));
        }

        return $next($request);
    }
}
