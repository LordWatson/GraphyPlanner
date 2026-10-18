<?php

namespace App\Http\Controllers\Campaigns;

use App\Actions\Campaigns\CompareCampaignsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Campaigns\CompareCampaignsRequest;
use App\Models\Campaign;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaigns enhancement C-7.4 — `GET /campaigns/compare?ids[]=…`: pick 2–4 of the org's
 * campaigns and see their results side by side.
 */
class CampaignCompareController extends Controller
{
    public const OPTION_LIMIT = 200;

    public function __invoke(CompareCampaignsRequest $request, CompareCampaignsAction $compare): Response
    {
        $ids = $request->ids();

        return Inertia::render('campaigns/compare', [
            'selected' => $ids,
            'max' => CompareCampaignsRequest::MAX,
            'comparison' => count($ids) >= 2 ? $compare($ids) : null,
            'options' => Campaign::query()
                ->where('org_id', $request->user()->org_id)
                ->with('client:id,name')
                ->orderByRaw("status = 'archived'")
                ->orderByDesc('start_date')
                ->latest('id')
                ->limit(self::OPTION_LIMIT)
                ->get(['id', 'name', 'code', 'client_id', 'status'])
                ->map(fn (Campaign $campaign) => [
                    'value' => (string) $campaign->id,
                    'label' => $campaign->name,
                    'client' => $campaign->client?->name,
                ])
                ->all(),
        ]);
    }
}
