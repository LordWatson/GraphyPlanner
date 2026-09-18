<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.3 — the client portal's landing page. Full needs-attention content lands in Step 6.4;
 * for now this simply confirms the portal shell/navigation renders for a scoped
 * `Role::ClientReviewer` session.
 */
class PortalDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('portal/dashboard', [
            'client' => ['name' => $request->user()->client->name],
        ]);
    }
}
