<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\GetFunnelMetricsAction;
use App\Actions\SuperAdmin\GetPlatformAnalyticsSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\AnalyticsFilterRequest;
use App\Models\Organization;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 15.4 — platform-wide analytics & reporting (`/super-admin/analytics`), filterable by date
 * range, organizations and metric sections. The filters can be saved as a report (Step 15.5).
 *
 * Step GTM-7.1 adds the funnel: trial → paid, time to first post, bio-page adoption, store GMV,
 * churn by plan and agency cap utilisation. Those are lifetime-to-date and ignore the filters, so
 * they are a separate prop rather than another section of the summary.
 */
class AnalyticsController extends Controller
{
    public function index(AnalyticsFilterRequest $request, GetPlatformAnalyticsSummaryAction $summary, GetFunnelMetricsAction $funnel): Response
    {
        return Inertia::render('super-admin/analytics/index', [
            'summary' => $summary($request->filters()),
            // Step GTM-7.1: lifetime-to-date business figures. Deliberately not range-filtered —
            // "how long does a new workspace take to publish" is not a question about last month.
            'funnel' => $funnel(),
            'organizationOptions' => Organization::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Organization $organization) => ['value' => $organization->id, 'label' => $organization->name])
                ->all(),
        ]);
    }
}
