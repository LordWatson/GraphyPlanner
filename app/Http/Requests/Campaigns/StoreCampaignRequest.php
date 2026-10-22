<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignStatus;
use App\Http\Requests\Concerns\ResolvesWorkspaceBrand;
use App\Models\Campaign;
use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCampaignRequest extends FormRequest
{
    use ResolvesWorkspaceBrand;

    public function authorize(): bool
    {
        return $this->user()->can('create', [Campaign::class, $this->client()]);
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
        return [
            ...CampaignAttributeRules::rules($this->client()),
            // A new campaign starts in planning or goes straight live; every later move goes
            // through `campaigns.transition` (Step C-0.3).
            'status' => ['nullable', Rule::in([CampaignStatus::Planning->value, CampaignStatus::Active->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CampaignAttributeRules::messages();
    }

    private function client(): Client
    {
        /** @var Client $client */
        $client = $this->brand();

        return $client;
    }
}
