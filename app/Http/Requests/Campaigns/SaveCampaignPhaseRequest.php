<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignColour;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Step C-3.1 — a phase must sit within the campaign's dates. While the campaign is undated a phase
 * may be undated too; once the campaign has dates, a dated phase must fall inside them.
 */
class SaveCampaignPhaseRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['nullable', 'date', 'required_with:ends_on'],
            'ends_on' => ['nullable', 'date', 'required_with:starts_on', 'after_or_equal:starts_on'],
            'colour' => ['nullable', new Enum(CampaignColour::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'A phase can’t end before it starts.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('starts_on')) {
                return;
            }

            $campaign = $this->campaign();
            $start = $campaign->start_date?->toDateString();
            $end = $campaign->end_date?->toDateString();
            $phaseStart = (string) $this->date('starts_on')?->toDateString();
            $phaseEnd = (string) $this->date('ends_on')?->toDateString();

            if ($start !== null && $phaseStart < $start) {
                $validator->errors()->add('starts_on', 'Phases must start on or after the campaign starts.');
            }

            if ($end !== null && $phaseEnd > $end) {
                $validator->errors()->add('ends_on', 'Phases must end on or before the campaign ends.');
            }
        });
    }

    public function campaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $campaign;
    }
}
