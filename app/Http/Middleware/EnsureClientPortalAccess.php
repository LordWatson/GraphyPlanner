<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\GridPlan;
use App\Models\Invoice;
use App\Models\Post;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step 6.3 — guards every `/portal/*` route: only an authenticated `Role::ClientReviewer` with a
 * `client_id` may enter, and every `Post`/`Invoice` route-model binding is checked against that
 * user's own `client_id` — never a value taken from the request — so a guessed id 404s instead of
 * leaking another client's data. This is applied generically (by parameter name) so future
 * `/portal/*` routes (Steps 6.4-6.6) inherit the isolation check automatically just by binding a
 * `{post}`/`{invoice}` route parameter. A bound `{post}` must also be in a client-visible status
 * (`PostStatus::clientVisible()`).
 */
class EnsureClientPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== Role::ClientReviewer || ! $user->client_id) {
            abort(403, 'This area is only available to client portal contacts.');
        }

        // Step I-1.5: an archived (soft-deleted) client's portal is closed until it's restored.
        if ($user->client === null) {
            abort(403, 'This client account has been archived.');
        }

        foreach (['post', 'invoice', 'campaign', 'grid'] as $parameter) {
            $model = $request->route($parameter);

            if (($model instanceof Post || $model instanceof Invoice || $model instanceof Campaign || $model instanceof GridPlan) && $model->client_id !== $user->client_id) {
                abort(404);
            }

            // Phase 18: a grid reaches the portal only once it's been sent to the client.
            if ($model instanceof GridPlan && ! $model->status->isClientVisible()) {
                abort(404);
            }

            // Pre-review internal stages (idea/draft/internal review) never reach the portal —
            // 404 rather than 403 so their existence isn't revealed either.
            if ($model instanceof Post && ! $model->status->isClientVisible()) {
                abort(404);
            }
        }

        return $next($request);
    }
}
