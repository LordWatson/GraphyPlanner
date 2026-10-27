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
     * Route-name equivalents between the two shells (Step B-4.1). When a business org lands on one
     * of the agency GET pages below, it's redirected to its own brand-implicit equivalent rather
     * than 404'd. Only the landing pages are mapped; agency write/engine routes a business org
     * should never call simply 404 (the feature isn't advertised).
     *
     * @return array<string, string>
     */
    private function equivalents(): array
    {
        return [
            // The agency overview / needs-attention → the business "Today".
            'dashboard' => 'business.today',
            'needs-attention' => 'business.today',
            // The client page's tabs → the matching business shell page.
            'clients.show' => 'business.accounts.index',
            'clients.posts.index' => 'business.posts.index',
            // The agency content hub → the business shell's own posts list.
            'content.index' => 'business.posts.index',
            'clients.media' => 'business.media.index',
            'clients.campaigns.index' => 'business.collections.index',
            'clients.ai.index' => 'business.ideas.index',
            'clients.brand-brain.edit' => 'business.brand.edit',
            // The agency hubs / per-client analytics → their business equivalents.
            'campaigns.index' => 'business.collections.index',
            'clients.analytics' => 'business.insights.index',
            // Step CG-1.2: the agency grids hub → the business grid planner.
            'grids.index' => 'business.grids.index',
        ];
    }
}
