<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\GetMonthlyInvoiceTotalsAction;
use App\Enums\Role;
use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with summary counts scoped to the user's organization.
     */
    public function __invoke(Request $request, GetMonthlyInvoiceTotalsAction $getMonthlyInvoiceTotals): Response
    {
        $user = $request->user();

        $clientsCount = $user->can('viewAny', Client::class)
            ? Client::query()->where('org_id', $user->org_id)->count()
            : 0;

        $canViewInvoiceTotals = in_array($user->role, [Role::Owner, Role::Strategist], true);

        return Inertia::render('dashboard', [
            'summary' => [
                'clients' => $clientsCount,
                'brands' => 0,
                'scheduledPosts' => 0,
            ],
            'invoiceTotals' => $canViewInvoiceTotals
                ? $getMonthlyInvoiceTotals($user->org_id)
                : [],
        ]);
    }
}
