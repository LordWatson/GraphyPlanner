<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Display the dashboard with summary counts scoped to the user's organization.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $clientsCount = $user->can('viewAny', Client::class)
            ? Client::query()->where('org_id', $user->org_id)->count()
            : 0;

        return Inertia::render('dashboard', [
            'summary' => [
                'clients' => $clientsCount,
                'brands' => 0,
                'scheduledPosts' => 0,
            ],
        ]);
    }
}
