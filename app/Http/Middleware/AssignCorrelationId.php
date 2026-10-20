<?php

namespace App\Http\Middleware;

use App\Support\Observability\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step I-16.1 / M-1.1 — global middleware (runs first): gives the request a correlation id,
 * reusing a well-formed one from the configured header (a proxy's request id) and minting a
 * UUID otherwise, puts it in `Context` and returns it on the response.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('observability.correlation_header', 'X-Request-Id');
        $incoming = $request->headers->get($header);
        $id = TraceContext::isValidId($incoming) ? (string) $incoming : (string) Str::uuid();

        Context::add(TraceContext::CORRELATION_ID, $id);

        $response = $next($request);
        $response->headers->set($header, $id);

        return $response;
    }
}
