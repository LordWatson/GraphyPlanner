<?php

namespace App\Http\Requests\Campaigns;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Step C-0.3: no `status` here — status changes go through `campaigns.transition`
 * (`TransitionCampaignRequest`), so they're checked against the state machine and logged.
 */
class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->campaign());
    }

    protected function prepareForValidation(): void
    {
        $this->merge(CampaignAttributeRules::normalise($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campaign = $this->campaign();

        return CampaignAttributeRules::rules($campaign->client, $campaign->id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CampaignAttributeRules::messages();
    }

    /**
     * Step C-3.1: the campaign's dates can't shrink past its phases. Moving everything is what
     * "Shift dates" (C-6.2) is for.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $campaign = $this->campaign();
            $start = $this->filled('start_date') ? (string) $this->date('start_date')?->toDateString() : null;
            $end = $this->filled('end_date') ? (string) $this->date('end_date')?->toDateString() : null;
            $phases = $campaign->phases()->whereNotNull('starts_on')->get(['name', 'starts_on', 'ends_on']);

            foreach ($phases as $phase) {
                if (($start !== null && $phase->starts_on?->toDateString() < $start)
                    || ($end !== null && $phase->ends_on?->toDateString() > $end)) {
                    $validator->errors()->add(
                        $start !== null && $phase->starts_on?->toDateString() < $start ? 'start_date' : 'end_date',
                        "The {$phase->name} phase runs outside these dates. Move or resize it first, or shift the whole campaign.",
                    );

                    return;
                }
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
