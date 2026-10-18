<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignMetric;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Step C-7.1 — replace the campaign's KPI targets (one per metric).
 */
class UpdateCampaignKpisRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $this->user()->can('update', $campaign);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kpis' => ['present', 'array', 'max:10'],
            'kpis.*.metric' => ['required', 'distinct', new Enum(CampaignMetric::class)],
            'kpis.*.target' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    /**
     * @return list<array{metric: CampaignMetric, target: float}>
     */
    public function targets(): array
    {
        return array_values(array_map(fn (array $row) => [
            'metric' => CampaignMetric::from((string) $row['metric']),
            'target' => (float) $row['target'],
        ], (array) $this->validated('kpis')));
    }
}
