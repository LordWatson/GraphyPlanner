<?php

namespace App\Http\Middleware;

use App\Enums\Role;
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
 * `{post}`/`{invoice}` route parameter.
 */
class EnsureClientPortalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== Role::ClientReviewer || ! $user->client_id) {
            abort(403, 'This area is only available to client portal contacts.');
        }

        foreach (['post', 'invoice'] as $parameter) {
            $model = $request->route($parameter);

            if (($model instanceof Post || $model instanceof Invoice) && $model->client_id !== $user->client_id) {
                abort(404);
            }
        }

        return $next($request);
    }
}
