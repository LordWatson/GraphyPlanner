<?php

namespace App\Http\Requests\Campaigns;

use App\Actions\Campaigns\CampaignStatusTransitionMap;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Step C-0.3 — a campaign status change. Rejected up front (422, no write) when the move isn't
 * in `CampaignStatusTransitionMap`; `TransitionCampaignStatusAction` re-checks under a row lock.
 */
class TransitionCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transition', $this->campaign());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campaign = $this->campaign();

        return [
            'to' => [
                'required',
                new Enum(CampaignStatus::class),
                function (string $attribute, mixed $value, Closure $fail) use ($campaign) {
                    $to = is_string($value) ? CampaignStatus::tryFrom($value) : null;

                    if ($to !== null && ! CampaignStatusTransitionMap::isAllowed($campaign->status, $to)) {
                        $fail(__('A :from campaign can’t move to :to.', [
                            'from' => strtolower($campaign->status->label()),
                            'to' => strtolower($to->label()),
                        ]));
                    }
                },
            ],
        ];
    }

    public function target(): CampaignStatus
    {
        return CampaignStatus::from($this->string('to')->toString());
    }

    public function campaign(): Campaign
    {
        /** @var Campaign $campaign */
        $campaign = $this->route('campaign');

        return $campaign;
    }
}
