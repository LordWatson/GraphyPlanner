<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignDeliverable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step C-3.2 — one planned deliverable. Its phase must belong to this campaign and its assignee
 * must be producing staff in the organization (C-4.4). Asset requirements (C-4.1) are a short
 * checklist. Owners/Strategists edit the plan; a Designer may only tick asset requirements.
 */
class SaveCampaignDeliverableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $campaign = $this->campaign();

        if ($this->user()->can('update', $campaign)) {
            return true;
        }

        // Designers produce: they can update the checklist on an existing deliverable, nothing else.
        return $this->route('deliverable') instanceof CampaignDeliverable
            && $this->user()->can('produce', $campaign)
            && array_keys($this->except(['_method'])) === ['asset_requirements'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campaign = $this->campaign();
        $partial = $this->isMethod('PATCH');
        $required = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'platform' => [$required, new Enum(Platform::class)],
            'campaign_phase_id' => ['nullable', 'integer', Rule::exists('campaign_phases', 'id')->where('campaign_id', $campaign->id)],
            'format' => ['nullable', 'string', 'max:50'],
            'pillar' => ['nullable', 'string', 'max:100'],
            'planned_for' => ['nullable', 'date'],
            'planned_time' => ['nullable', 'date_format:H:i'],
            'assignee_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('org_id', $campaign->org_id)->whereIn('role', ['owner', 'strategist', 'designer']),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'asset_requirements' => ['nullable', 'array', 'max:10'],
            'asset_requirements.*.label' => ['required', 'string', 'max:100'],
            'asset_requirements.*.met' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (isset($data['asset_requirements'])) {
            $data['asset_requirements'] = array_values(array_map(fn (array $requirement) => [
                'label' => trim((string) $requirement['label']),
                'met' => (bool) ($requirement['met'] ?? false),
            ], $data['asset_requirements']));
        }

        return $data;
    }

    public function campaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $campaign;
    }
}
