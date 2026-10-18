<?php

namespace App\Actions\Campaigns;

use App\Enums\CampaignMetric;
use App\Models\Campaign;
use App\Models\CampaignKpi;
use App\Services\CampaignKpiService;
use App\Support\Campaigns\CampaignPresenter;
use Illuminate\Support\Facades\Log;

/**
 * Campaigns enhancement C-7.4 — campaigns side by side: who they were for, their objective and
 * how long they ran (so the comparison is fair), then every metric's actual against its target
 * where one was set. Metrics no campaign can report yet are left out rather than shown as zeros.
 */
class CompareCampaignsAction
{
    public function __construct(private readonly CampaignKpiService $kpis) {}

    /**
     * @param  list<int>  $ids  already validated as the viewer's org's campaigns
     * @return array{campaigns: list<array<string, mixed>>, metrics: list<array<string, mixed>>}
     */
    public function __invoke(array $ids): array
    {
        $campaigns = Campaign::query()
            ->whereIn('id', $ids)
            ->with(['client:id,name', 'organization:id,default_timezone', 'kpis'])
            ->get()
            ->sortBy(fn (Campaign $campaign) => array_search($campaign->id, $ids, true))
            ->values();

        $actuals = $campaigns->mapWithKeys(fn (Campaign $campaign) => [$campaign->id => $this->kpis->actuals($campaign)])->all();

        $metrics = [];

        foreach (CampaignMetric::cases() as $metric) {
            $cells = $campaigns->map(function (Campaign $campaign) use ($metric, $actuals) {
                $kpi = $campaign->kpis->first(fn (CampaignKpi $kpi) => $kpi->metric === $metric);
                $row = CampaignKpiService::row($metric, $kpi === null ? 0.0 : (float) $kpi->target, $actuals[$campaign->id][$metric->value]);

                return [
                    'campaign_id' => $campaign->id,
                    'actual' => $row['actual'],
                    'available' => $row['available'],
                    'target' => $kpi === null ? null : $row['target'],
                    'met' => $kpi === null ? null : $row['met'],
                ];
            })->values()->all();

            if (collect($cells)->every(fn (array $cell) => ! $cell['available'] && $cell['target'] === null)) {
                continue;
            }

            $metrics[] = [
                'metric' => $metric->value,
                'label' => $metric->label(),
                'unit' => $metric->unit(),
                'lower_is_better' => $metric->lowerIsBetter(),
                'cells' => $cells,
            ];
        }

        Log::info('Campaigns compared', ['campaign_ids' => $ids]);

        return [
            'campaigns' => array_values($campaigns->map(fn (Campaign $campaign) => [
                ...CampaignPresenter::chip($campaign),
                'status' => $campaign->status->value,
                'status_label' => $campaign->status->label(),
                'client_name' => $campaign->client?->name,
                'objective_label' => $campaign->objective?->label(),
                'start_date' => $campaign->start_date?->toDateString(),
                'end_date' => $campaign->end_date?->toDateString(),
                'duration_days' => $campaign->start_date && $campaign->end_date
                    ? (int) $campaign->start_date->diffInDays($campaign->end_date) + 1
                    : null,
            ])->all()),
            'metrics' => $metrics,
        ];
    }
}
