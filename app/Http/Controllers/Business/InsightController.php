<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\SummarizeInsightsAction;
use App\Actions\Business\GetBusinessInsightsAction;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-4.1 / B-8 — the business shell's "Insights": single-brand consistency, reach/engagement,
 * top posts, follower growth and best times to post, plus plain-language AI takeaways (B-8.2). No
 * revenue, client or staff-cost sections, ever. The heavy read is deferred; the AI takeaways are a
 * separate deferred prop so an empty/disabled AI never slows the page.
 */
class InsightController extends Controller
{
    public function index(
        Request $request,
        CurrentWorkspace $workspace,
        GetBusinessInsightsAction $insights,
        SummarizeInsightsAction $summarize,
    ): Response {
        $brand = $workspace->brand();

        // Insights read the brand's posts; gate on the same ability the calendar/posts use.
        $this->authorize('viewAny', [Post::class, $brand]);

        $timezone = $workspace->organization()->default_timezone ?? 'UTC';

        // Built at most once per request and shared by both deferred props (same default group), so
        // the summariser reuses the numbers rather than recomputing them.
        $computed = null;
        $resolve = function () use (&$computed, $insights, $brand, $timezone): array {
            return $computed ??= $insights($brand, $timezone);
        };

        return Inertia::render('business/insights', [
            'insights' => Inertia::defer(fn () => $resolve()),
            'takeaways' => Inertia::defer(fn () => $summarize($brand, $resolve(), $timezone)),
            'timezone' => $timezone,
        ]);
    }
}
