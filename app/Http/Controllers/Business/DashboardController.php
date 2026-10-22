<?php

namespace App\Http\Controllers\Business;

use App\Actions\Business\GetBusinessDashboardAction;
use App\Http\Controllers\Controller;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.3 — the business shell's home: "Today". Thin, like every business controller: it reads
 * the primary brand from {@see CurrentWorkspace} (no `{client}` in the URL) and hands it to the
 * dashboard Action, which does all the counting SQL-side.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $workspace, GetBusinessDashboardAction $dashboard): Response
    {
        $brand = $workspace->brand();
        $timezone = $workspace->organization()->default_timezone ?? 'UTC';

        // Every section is a fixed, bounded set of queries (Step I-2.2), so the whole dashboard is
        // cheap to load up front — no deferral, so the brand sees a complete first paint.
        return Inertia::render('business/today', [
            'dashboard' => $dashboard($request->user(), $brand, $timezone),
            'timezone' => $timezone,
        ]);
    }
}
