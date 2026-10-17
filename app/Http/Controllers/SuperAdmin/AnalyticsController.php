<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\GetPlatformAnalyticsSummaryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\AnalyticsFilterRequest;
use App\Models\Organization;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 15.4 — platform-wide analytics & reporting (`/super-admin/analytics`), filterable by date
 * range, organizations and metric sections. The filters can be saved as a report (Step 15.5).
 */
class AnalyticsController extends Controller
{
    public function index(AnalyticsFilterRequest $request, GetPlatformAnalyticsSummaryAction $summary): Response
    {
        return Inertia::render('super-admin/analytics/index', [
            'summary' => $summary($request->filters()),
            'organizationOptions' => Organization::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Organization $organization) => ['value' => $organization->id, 'label' => $organization->name])
                ->all(),
        ]);
    }
}
