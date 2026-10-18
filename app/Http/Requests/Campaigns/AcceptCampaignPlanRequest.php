<?php

namespace App\Http\Requests\Campaigns;

use App\Actions\Campaigns\PlanCampaignAction;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step C-3.3 — the suggestions a person ticked. They round-tripped through the browser, so every
 * field is validated again: platform must still be publishable, phase must be this campaign's,
 * dates inside the campaign.
 */
class AcceptCampaignPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->campaign());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campaign = $this->campaign();
        $dateRules = ['required', 'date_format:Y-m-d'];

        if ($campaign->start_date !== null) {
            $dateRules[] = 'after_or_equal:'.$campaign->start_date->toDateString();
        }

        if ($campaign->end_date !== null) {
            $dateRules[] = 'before_or_equal:'.$campaign->end_date->toDateString();
        }

        return [
            'items' => ['required', 'array', 'min:1', 'max:'.PlanCampaignAction::MAX_SUGGESTIONS],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.platform' => ['required', Rule::in(app(PlanCampaignAction::class)->allowedPlatforms($campaign))],
            'items.*.campaign_phase_id' => ['nullable', 'integer', Rule::exists('campaign_phases', 'id')->where('campaign_id', $campaign->id)],
            'items.*.format' => ['nullable', 'string', 'max:50'],
            'items.*.pillar' => ['nullable', 'string', 'max:100'],
            'items.*.planned_for' => $dateRules,
            'items.*.planned_time' => ['nullable', 'date_format:H:i'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function campaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $campaign;
    }
}
