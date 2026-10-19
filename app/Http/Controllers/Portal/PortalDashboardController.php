<?php

namespace App\Http\Controllers\Portal;

use App\Actions\Portal\GetPortalDashboardItemsAction;
use App\Http\Controllers\Controller;
use App\Support\Clients\ConnectSlots;
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
        $client = $request->user()->client;

        return Inertia::render('portal/dashboard', [
            'client' => ['name' => $client->name],
            'items' => $getPortalDashboardItems($request->user()),
            // Step M-2.8: "Connect your accounts" — only the client's own slots, and only what's
            // needed to connect them.
            'connectAccounts' => ConnectSlots::forList($client->socialAccounts()->orderBy('platform')->get()),
        ]);
    }
}
