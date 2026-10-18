<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\Platform;
use App\Models\Campaign;
use App\Models\CampaignPhase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Step C-3.2 — "Add N per week on {platforms} through {phase}".
 */
class BulkAddDeliverablesRequest extends FormRequest
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
        return [
            'per_week' => ['required', 'integer', 'min:1', 'max:14'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['distinct', new Enum(Platform::class)],
            'campaign_phase_id' => ['nullable', 'integer', Rule::exists('campaign_phases', 'id')->where('campaign_id', $this->campaign()->id)],
            'planned_time' => ['nullable', 'date_format:H:i'],
            'title_prefix' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return list<Platform>
     */
    public function platforms(): array
    {
        return array_values(array_map(fn ($value) => Platform::from((string) $value), (array) $this->input('platforms', [])));
    }

    public function phase(): ?CampaignPhase
    {
        $id = $this->integer('campaign_phase_id');

        return $id ? $this->campaign()->phases()->whereKey($id)->first() : null;
    }

    public function campaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $campaign;
    }
}
