<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\SuperAdmin\GetPartnerUsageAction;
use App\Actions\SuperAdmin\ListProductFeedbackAction;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\ProductFeedback;
use App\Support\Analytics\AnalyticsRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step M-8.2 — the design-partner release console: the partner usage view (who's really using
 * Alima) and the in-app feedback inbox. Super-admin only (the group middleware). Thin: resolve the
 * range/filter, call an Action, render.
 */
class PartnerController extends Controller
{
    public function index(Request $request, GetPartnerUsageAction $usage, ListProductFeedbackAction $feedback): Response
    {
        $range = AnalyticsRange::fromArray(['range' => $request->query('range', AnalyticsRange::DEFAULT_PRESET)]);

        return Inertia::render('super-admin/partners/index', [
            'usage' => $usage($range),
            'recentFeedback' => $feedback->recent(),
            'ranges' => $this->rangeOptions(),
        ]);
    }

    public function feedback(Request $request, ListProductFeedbackAction $list): Response
    {
        $orgId = $request->integer('org') ?: null;

        return Inertia::render('super-admin/feedback/index', [
            'feedback' => $list($orgId)->through(ListProductFeedbackAction::row(...)),
            'filters' => ['org' => $orgId],
            // The orgs that have ever sent feedback, so the filter only lists ones with notes.
            'organizations' => Organization::query()
                ->whereIn('id', ProductFeedback::query()->select('org_id')->distinct())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Organization $org) => ['id' => $org->id, 'name' => $org->name])
                ->all(),
        ]);
    }

    /**
     * The preset ranges offered on the usage view (custom ranges aren't needed here).
     *
     * @return list<array{value: string, label: string}>
     */
    private function rangeOptions(): array
    {
        return [
            ['value' => '7d', 'label' => 'Last 7 days'],
            ['value' => '30d', 'label' => 'Last 30 days'],
            ['value' => '90d', 'label' => 'Last 90 days'],
        ];
    }
}
