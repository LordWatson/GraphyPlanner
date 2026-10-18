<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step I-1.1 — appended to the `web` group. The preview owner may only ever be authenticated on
 * `/preview/*`, where `EnsurePreviewMode` resolves it per request. Any other URL that finds it
 * authenticated (a session persisted before preview stopped calling `Auth::login()`, or a future
 * regression) is signed out and rejected, so the preview identity can never reach the real,
 * writable routes.
 */
class RejectPreviewUserOutsidePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('preview', 'preview/*') || ! $request->user()?->isPreviewUser()) {
            return $next($request);
        }

        Log::warning('Preview user authenticated outside preview mode', [
            'path' => $request->path(),
            'method' => $request->method(),
            'ip' => $request->ip(),
        ]);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(403, 'The preview account can only be used inside preview mode.');
    }
}
