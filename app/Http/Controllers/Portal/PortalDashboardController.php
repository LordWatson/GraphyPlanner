<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\GetPortalDashboardItemsAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.4 — the client portal's landing page: a needs-attention view scoped to the logged-in
 * contact's own `client_id` (outstanding approvals, recently requested changes, and upcoming
 * scheduled posts), built via `GetPortalDashboardItemsAction`.
 */
class PortalDashboardController extends Controller
{
    public function index(Request $request, GetPortalDashboardItemsAction $getPortalDashboardItems): Response
    {
        return Inertia::render('portal/dashboard', [
            'client' => ['name' => $request->user()->client->name],
            'items' => $getPortalDashboardItems($request->user()),
        ]);
    }
}
