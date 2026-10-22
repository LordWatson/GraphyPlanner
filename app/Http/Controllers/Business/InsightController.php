<?php

namespace App\Http\Controllers\Business;

use App\Actions\Business\GetBusinessInsightsAction;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 — the business shell's "Insights": single-brand publishing consistency, available
 * today. The reach/engagement sections (and the AI takeaways) arrive with Phase B-8 on the same
 * page; there are no revenue, client or staff-cost sections, ever.
 */
class InsightController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, GetBusinessInsightsAction $insights): Response
    {
        $brand = $workspace->brand();

        // Insights read the brand's posts; gate on the same ability the calendar/posts use.
        $this->authorize('viewAny', [Post::class, $brand]);

        $timezone = $workspace->organization()->default_timezone ?? 'UTC';

        return Inertia::render('business/insights', [
            'insights' => Inertia::defer(fn () => $insights($brand, $timezone)),
            'timezone' => $timezone,
        ]);
    }
}
