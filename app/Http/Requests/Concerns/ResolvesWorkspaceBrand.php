<?php

namespace App\Http\Requests\Concerns;

use App\Models\Client;
use App\Services\Workspace\CurrentWorkspace;

/**
 * Step B-4.1 — lets a shared Form Request work for both shells. Agency controllers route-bind a
 * `{client}`; the business shell has no `{client}` in the URL (the brand is implicit), so the
 * request falls back to the signed-in business workspace's primary brand. Agency behaviour is
 * unchanged — the route parameter still wins when it's present.
 */
trait ResolvesWorkspaceBrand
{
    /**
     * The client this request acts on: the route-bound `{client}` when present, otherwise the
     * current business workspace's primary brand. Null for an agency request with no `{client}`.
     */
    public function brand(): ?Client
    {
        $routeClient = $this->route('client');

        if ($routeClient instanceof Client) {
            return $routeClient;
        }

        return app(CurrentWorkspace::class)->brand();
    }

    public function brandId(): ?int
    {
        return $this->brand()?->id;
    }
}
