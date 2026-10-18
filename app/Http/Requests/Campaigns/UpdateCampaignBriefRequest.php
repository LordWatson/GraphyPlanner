<?php

namespace App\Http\Requests\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignBrief;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step C-2.1 — the brief's content. List fields take short lines; blank lines are dropped.
 */
class UpdateCampaignBriefRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $this->user()->can('update', $campaign);
    }

    protected function prepareForValidation(): void
    {
        $lists = [];

        foreach (CampaignBrief::LIST_FIELDS as $field) {
            $value = $this->input($field);

            if (is_array($value)) {
                $lists[$field] = array_values(array_filter(array_map(
                    fn ($line) => is_string($line) ? trim($line) : $line,
                    $value,
                ), fn ($line) => $line !== '' && $line !== null));
            }
        }

        $this->merge($lists);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prose = ['nullable', 'string', 'max:5000'];
        $list = ['nullable', 'array', 'max:20'];
        $line = ['string', 'max:300'];

        return [
            'background' => $prose,
            'objective_detail' => $prose,
            'audience' => $prose,
            'key_messages' => $list,
            'key_messages.*' => $line,
            'offer' => $prose,
            'call_to_action' => ['nullable', 'string', 'max:255'],
            'mandatories' => $list,
            'mandatories.*' => $line,
            'avoid' => $list,
            'avoid.*' => $line,
            'deliverables_summary' => $prose,
            'success_measures' => $prose,
        ];
    }
}
