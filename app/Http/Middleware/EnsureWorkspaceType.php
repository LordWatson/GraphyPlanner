<?php

namespace App\Http\Middleware;

use App\Enums\OrganizationType;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step B-1.4 — `workspace:agency` / `workspace:business` keep each kind of organization out of the
 * other's shell. The first layer of defence in depth (policies are the second), so a hand-typed
 * agency URL can't reach a business org's data and vice versa.
 *
 * When the workspace has a direct equivalent on the other side (filled in as the business shell
 * lands in Phase B-4, e.g. `clients.brand-brain.edit` → `brand.edit`), the user is redirected
 * there; otherwise the response is a 404, not a 403, so the feature is never advertised.
 */
class EnsureWorkspaceType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $required = OrganizationType::from($type);
        $organization = $request->user()?->organization;

        // Org-less accounts (platform super admins) are handled by `org.member`, which runs first
        // and redirects them out of the workspace — don't second-guess that here.
        if ($organization === null || $organization->type === $required) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        $equivalent = $name !== null ? ($this->equivalents()[$name] ?? null) : null;

        if ($equivalent !== null && Route::has($equivalent)) {
            return redirect()->route($equivalent);
        }

        abort(404);
    }

    /**
     * Route-name equivalents between the two shells. Empty until the business shell exists
     * (Phase B-4); every guarded agency route therefore 404s for a business org for now, at which
     * point entries like `clients.brand-brain.edit` → `brand.edit` turn the 404s into redirects.
     *
     * @return array<string, string>
     */
    private function equivalents(): array
    {
        return [];
    }
}
