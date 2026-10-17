<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\GetSuperAdminOverviewAction;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 15.1 — the super admin landing page (`/super-admin`), where a super admin arrives after login.
 */
class DashboardController extends Controller
{
    public function __invoke(GetSuperAdminOverviewAction $overview): Response
    {
        return Inertia::render('super-admin/dashboard', $overview());
    }
}
