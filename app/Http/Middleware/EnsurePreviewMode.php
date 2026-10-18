<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 0.14 — Preview mode guard, applied to every `/preview/*` route.
 *
 * - The `preview` shared Inertia prop (see `HandleInertiaRequests::share()`, matched by URL
 *   prefix rather than a request attribute so it's captured before route middleware runs) drives
 *   the frontend banner from spec §12 on every preview page.
 * - Resolves the frozen "Preview Studio" owner (seeded by `PreviewSeeder`) as the user for *this
 *   request only*, so existing controllers/policies — which all scope by `$user->org_id` —
 *   transparently return only that organization's fixture data without any per-controller
 *   preview branching. Step I-1.1: this is `Auth::setUser()`, never `Auth::login()` — nothing is
 *   written to the session, so the preview identity can't leak onto the real routes, and a
 *   logged-in visitor keeps their own session untouched (they are themselves again on the next
 *   non-preview request). `RejectPreviewUserOutsidePreview` is the backstop for any old session
 *   that still carries the preview owner.
 * - Hard-blocks every non-safe HTTP method with a 403 *before* any controller/action runs, so
 *   preview visitors can never write to (or even attempt to write to) production data. This is
 *   deliberately blunt: preview is read-only, full stop — publish-adjacent routes will get the
 *   same treatment automatically once Phase 1 adds them under this prefix.
 */
class EnsurePreviewMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('get') && ! $request->isMethod('head')) {
            Log::warning('Rejected write attempt in preview mode', [
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            abort(403, 'Preview mode is read-only — no changes can be made here.');
        }

        $organization = Organization::firstWhere('slug', Organization::PREVIEW_SLUG);
        $previewUser = $organization ? User::firstWhere(['org_id' => $organization->id, 'email' => User::PREVIEW_EMAIL]) : null;

        if (! $previewUser) {
            abort(404, 'Preview mode is not available yet — run the preview seeder.');
        }

        // Swap the identity for this request only, and put the previous one (or none) back
        // afterwards, so a long-lived guard instance (tests, Octane) never carries it over.
        $guard = Auth::guard();
        $previousUser = $guard->user();

        $guard->setUser($previewUser);

        try {
            return $next($request);
        } finally {
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
        }
    }
}
